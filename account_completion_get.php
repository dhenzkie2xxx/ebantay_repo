<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function get_bearer_or_query_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_query_token();
  if ($token === "") {
    out(401, ["ok" => false, "message" => "Missing token"]);
  }

  $user = auth_get_user_by_token($pdo, $token);
  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if (strtolower((string)($user["role"] ?? "")) !== "citizen") {
    out(403, ["ok" => false, "message" => "Only citizen users can access this endpoint"]);
  }

  $userId = (int)$user["id"];

  // -------------------------------------------------------
  // user + profile
  // -------------------------------------------------------
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.role,
      u.valid,
      u.account_status,
      u.account_flag_status,
      u.false_report_count,
      u.false_alarm_count,
      u.flagged_reason,
      u.flagged_at,
      u.suspended_at,
      u.suspension_reason,
      u.is_email_verified,
      u.approved_by,
      u.approved_at,
      u.rejected_reason,
      u.created_at,
      u.updated_at,

      up.mobile_number,
      up.address_text,
      up.address_lat,
      up.address_lng,
      up.barangay,
      up.city_municipality,
      up.province,
      up.region,
      up.sex_gender,
      up.birth_date,
      up.age,
      up.civil_status,
      up.occupation,
      up.created_at AS profile_created_at,
      up.updated_at AS profile_updated_at

    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    out(404, ["ok" => false, "message" => "User not found"]);
  }

  $profileProvince = normalize_scope_value($row["province"] ?? null);
  $profileCity = normalize_scope_value($row["city_municipality"] ?? null);

  // -------------------------------------------------------
  // Active requirements for this user scope
  // Includes:
  // - global system requirements
  // - province/city scoped requirements
  // - station-scoped requirements through stations in same province/city
  // -------------------------------------------------------
  $requirementsSql = "
    SELECT DISTINCT
      r.id,
      r.requirement_code AS code,
      r.requirement_name AS name,
      r.is_required,
      r.is_system,
      r.station_id,
      r.city_municipality,
      r.province,
      r.active,
      r.created_by,
      r.created_at,
      r.updated_at
    FROM user_verification_requirements r
    LEFT JOIN police_stations ps
      ON ps.id = r.station_id
    WHERE r.active = 1
      AND (
        (
          r.station_id IS NULL
          AND r.city_municipality IS NULL
          AND r.province IS NULL
        )
  ";

  $params = [];

  if ($profileProvince && $profileCity) {
    $requirementsSql .= "
        OR (
          r.station_id IS NULL
          AND LOWER(COALESCE(r.province, '')) = LOWER(?)
          AND LOWER(COALESCE(r.city_municipality, '')) = LOWER(?)
        )
        OR (
          r.station_id IS NOT NULL
          AND LOWER(COALESCE(ps.province, '')) = LOWER(?)
          AND LOWER(COALESCE(ps.city_municipality, '')) = LOWER(?)
        )
    ";
    array_push($params, $profileProvince, $profileCity, $profileProvince, $profileCity);
  }

  $requirementsSql .= "
      )
    ORDER BY r.is_system DESC, r.requirement_name ASC
  ";

  $reqStmt = $pdo->prepare($requirementsSql);
  $reqStmt->execute($params);
  $requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // -------------------------------------------------------
  // User submissions
  // latest per requirement
  // -------------------------------------------------------
  $subStmt = $pdo->prepare("
    SELECT
      s.id,
      s.user_id,
      s.requirement_id,
      s.file_name,
      s.mime_type,
      s.file_size,
      s.status,
      s.remarks,
      s.uploaded_at,
      s.reviewed_at,
      s.reviewed_by,
      r.requirement_code,
      r.requirement_name
    FROM user_requirement_submissions s
    INNER JOIN user_verification_requirements r
      ON r.id = s.requirement_id
    WHERE s.user_id = ?
    ORDER BY s.requirement_id ASC, s.uploaded_at DESC, s.id DESC
  ");
  $subStmt->execute([$userId]);
  $submissionsRaw = $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $latestByRequirement = [];
  foreach ($submissionsRaw as $s) {
    $reqId = (int)$s["requirement_id"];
    if (!isset($latestByRequirement[$reqId])) {
      $latestByRequirement[$reqId] = $s;
    }
  }

  $baseUrl = "";
  $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
  $host = $_SERVER["HTTP_HOST"] ?? "";
  if ($host !== "") {
    $baseUrl = $scheme . "://" . $host;
  }

  $tokenParam = rawurlencode($token);

  $submissions = [];
  foreach ($latestByRequirement as $reqId => $s) {
    $docId = (int)$s["id"];
    $path = "/get_user_document.php?id=" . $docId . "&token=" . $tokenParam;

    $submissions[] = [
      "id" => $docId,
      "user_id" => (int)$s["user_id"],
      "requirement_id" => (int)$s["requirement_id"],
      "requirement_code" => $s["requirement_code"],
      "requirement_name" => $s["requirement_name"],
      "file_name" => $s["file_name"],
      "mime_type" => $s["mime_type"],
      "file_size" => $s["file_size"] !== null ? (int)$s["file_size"] : null,
      "status" => strtolower((string)($s["status"] ?? "submitted")),
      "remarks" => $s["remarks"],
      "uploaded_at" => $s["uploaded_at"],
      "reviewed_at" => $s["reviewed_at"],
      "reviewed_by" => $s["reviewed_by"] !== null ? (int)$s["reviewed_by"] : null,
      "preview_url" => $baseUrl . $path . "&mode=preview",
      "download_url" => $baseUrl . $path . "&mode=download"
    ];
  }

  // -------------------------------------------------------
  // latest verification request
  // -------------------------------------------------------
  $vrStmt = $pdo->prepare("
    SELECT
      id,
      user_id,
      status,
      submitted_at,
      reviewed_at,
      reviewed_by,
      remarks
    FROM user_verification_requests
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $vrStmt->execute([$userId]);
  $verificationRequest = $vrStmt->fetch(PDO::FETCH_ASSOC);

  // -------------------------------------------------------
  // build requirement items with submission status
  // -------------------------------------------------------
  $submissionByRequirementId = [];
  foreach ($submissions as $s) {
    $submissionByRequirementId[(int)$s["requirement_id"]] = $s;
  }

  $requirementItems = array_map(function ($r) use ($submissionByRequirementId) {
    $reqId = (int)$r["id"];
    $submission = $submissionByRequirementId[$reqId] ?? null;

    return [
      "id" => $reqId,
      "code" => $r["code"],
      "name" => $r["name"],
      "is_required" => (int)$r["is_required"] === 1,
      "is_system" => (int)$r["is_system"] === 1,
      "station_id" => $r["station_id"] !== null ? (int)$r["station_id"] : null,
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "active" => (int)$r["active"] === 1,
      "submission" => $submission ? [
        "id" => (int)$submission["id"],
        "file_name" => $submission["file_name"],
        "mime_type" => $submission["mime_type"],
        "file_size" => $submission["file_size"],
        "status" => $submission["status"],
        "remarks" => $submission["remarks"],
        "uploaded_at" => $submission["uploaded_at"],
        "reviewed_at" => $submission["reviewed_at"],
        "preview_url" => $submission["preview_url"],
        "download_url" => $submission["download_url"]
      ] : null
    ];
  }, $requirements);

  // -------------------------------------------------------
  // completion counters
  // -------------------------------------------------------
  $requiredCount = 0;
  $submittedRequiredCount = 0;

  foreach ($requirementItems as $item) {
    if (!empty($item["is_required"])) {
      $requiredCount++;
      if (!empty($item["submission"])) {
        $submittedRequiredCount++;
      }
    }
  }

  out(200, [
    "ok" => true,
    "user" => [
      "id" => (int)$row["id"],
      "firstname" => $row["firstname"],
      "lastname" => $row["lastname"],
      "email" => $row["email"],
      "username" => $row["username"],
      "role" => $row["role"],
      "valid" => $row["valid"],
      "account_status" => strtolower((string)($row["account_status"] ?? "pending")),
      "account_flag_status" => strtolower((string)($row["account_flag_status"] ?? "none")),
      "false_report_count" => isset($row["false_report_count"]) ? (int)$row["false_report_count"] : 0,
      "false_alarm_count" => isset($row["false_alarm_count"]) ? (int)$row["false_alarm_count"] : 0,
      "flagged_reason" => $row["flagged_reason"],
      "flagged_at" => $row["flagged_at"],
      "suspended_at" => $row["suspended_at"],
      "suspension_reason" => $row["suspension_reason"],
      "is_email_verified" => (int)($row["is_email_verified"] ?? 0),
      "approved_by" => $row["approved_by"] !== null ? (int)$row["approved_by"] : null,
      "approved_at" => $row["approved_at"],
      "rejected_reason" => $row["rejected_reason"],
      "created_at" => $row["created_at"],
      "updated_at" => $row["updated_at"]
    ],
    "profile" => [
      "mobile_number" => $row["mobile_number"],
      "address_text" => $row["address_text"],
      "address_lat" => $row["address_lat"] !== null ? (float)$row["address_lat"] : null,
      "address_lng" => $row["address_lng"] !== null ? (float)$row["address_lng"] : null,
      "barangay" => $row["barangay"],
      "city_municipality" => $row["city_municipality"],
      "province" => $row["province"],
      "region" => $row["region"],
      "sex_gender" => $row["sex_gender"],
      "birth_date" => $row["birth_date"],
      "age" => $row["age"] !== null ? (int)$row["age"] : null,
      "civil_status" => $row["civil_status"],
      "occupation" => $row["occupation"],
      "profile_created_at" => $row["profile_created_at"],
      "profile_updated_at" => $row["profile_updated_at"]
    ],
    "requirements" => $requirementItems,
    "submissions" => $submissions,
    "verification_request" => $verificationRequest ? [
      "id" => (int)$verificationRequest["id"],
      "user_id" => (int)$verificationRequest["user_id"],
      "status" => strtolower((string)($verificationRequest["status"] ?? "pending")),
      "submitted_at" => $verificationRequest["submitted_at"],
      "reviewed_at" => $verificationRequest["reviewed_at"],
      "reviewed_by" => $verificationRequest["reviewed_by"] !== null ? (int)$verificationRequest["reviewed_by"] : null,
      "remarks" => $verificationRequest["remarks"]
    ] : null,
    "completion" => [
      "required_total" => $requiredCount,
      "required_submitted" => $submittedRequiredCount,
      "is_ready_for_submission" => $requiredCount > 0 && $requiredCount === $submittedRequiredCount
    ]
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}