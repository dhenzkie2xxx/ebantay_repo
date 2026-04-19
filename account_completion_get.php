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

function get_user_profile(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("
    SELECT
      user_id,
      mobile_number,
      address_text,
      address_lat,
      address_lng,
      barangay,
      city_municipality,
      province,
      region
    FROM user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}

function requirement_applies_to_user(PDO $pdo, array $requirement, ?array $profile): bool {
  if (!$profile) {
    return (int)($requirement["is_system"] ?? 0) === 1;
  }

  $reqStationId = $requirement["station_id"] !== null ? (int)$requirement["station_id"] : null;
  $reqProvince = normalize_scope_value($requirement["province"] ?? null);
  $reqCity = normalize_scope_value($requirement["city_municipality"] ?? null);

  $userProvince = normalize_scope_value($profile["province"] ?? null);
  $userCity = normalize_scope_value($profile["city_municipality"] ?? null);

  if ($reqStationId === null && $reqProvince === null && $reqCity === null) {
    return true;
  }

  if ($reqStationId === null) {
    if (!$userProvince || !$userCity || !$reqProvince || !$reqCity) return false;

    return
      strcasecmp($userProvince, $reqProvince) === 0 &&
      strcasecmp($userCity, $reqCity) === 0;
  }

  $stmt = $pdo->prepare("
    SELECT
      province,
      city_municipality
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$reqStationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) return false;

  $stationProvince = normalize_scope_value($station["province"] ?? null);
  $stationCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$userProvince || !$userCity || !$stationProvince || !$stationCity) return false;

  return
    strcasecmp($userProvince, $stationProvince) === 0 &&
    strcasecmp($userCity, $stationCity) === 0;
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $user = auth_get_user_by_token($pdo);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (function_exists("auth_check_token_expired") && auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if (strtolower((string)($user["role"] ?? "")) !== "citizen") {
    out(403, ["ok" => false, "message" => "Only citizen users can access account completion"]);
  }

  $userId = (int)$user["id"];
  $profile = get_user_profile($pdo, $userId);

  $safeProfile = [
    "mobile_number" => $profile["mobile_number"] ?? null,
    "address_text" => $profile["address_text"] ?? null,
    "address_lat" => isset($profile["address_lat"]) && $profile["address_lat"] !== null ? (float)$profile["address_lat"] : null,
    "address_lng" => isset($profile["address_lng"]) && $profile["address_lng"] !== null ? (float)$profile["address_lng"] : null,
    "barangay" => $profile["barangay"] ?? null,
    "city_municipality" => $profile["city_municipality"] ?? null,
    "province" => $profile["province"] ?? null,
    "region" => $profile["region"] ?? null,
  ];

  $requirementsStmt = $pdo->query("
    SELECT
      id,
      requirement_code,
      requirement_name,
      is_required,
      is_system,
      station_id,
      city_municipality,
      province,
      active
    FROM user_verification_requirements
    WHERE active = 1
    ORDER BY is_system DESC, requirement_name ASC
  ");
  $allRequirements = $requirementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $applicableRequirements = [];
  foreach ($allRequirements as $req) {
    if (requirement_applies_to_user($pdo, $req, $profile)) {
      $applicableRequirements[] = $req;
    }
  }

  $submissionRows = [];
  if ($userId > 0) {
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
        s.reviewed_by
      FROM user_requirement_submissions s
      WHERE s.user_id = ?
      ORDER BY s.requirement_id ASC, s.uploaded_at DESC, s.id DESC
    ");
    $subStmt->execute([$userId]);
    $submissionRows = $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  $latestSubmissionByRequirement = [];
  foreach ($submissionRows as $s) {
    $reqId = (int)$s["requirement_id"];
    if (!isset($latestSubmissionByRequirement[$reqId])) {
      $latestSubmissionByRequirement[$reqId] = $s;
    }
  }

  $token = bearer_token();
  $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
  $host = $_SERVER["HTTP_HOST"] ?? "";
  $baseUrl = $host !== "" ? $scheme . "://" . $host : "";
  $tokenParam = rawurlencode($token);

  $requirements = [];
  foreach ($applicableRequirements as $req) {
    $reqId = (int)$req["id"];
    $latest = $latestSubmissionByRequirement[$reqId] ?? null;

    $submissionPayload = null;
    if ($latest) {
      $docPath = "/get_user_document.php?id=" . (int)$latest["id"] . "&token=" . $tokenParam;

      $submissionPayload = [
        "id" => (int)$latest["id"],
        "user_id" => (int)$latest["user_id"],
        "requirement_id" => (int)$latest["requirement_id"],
        "file_name" => $latest["file_name"] ?? null,
        "mime_type" => $latest["mime_type"] ?? null,
        "file_size" => isset($latest["file_size"]) && $latest["file_size"] !== null ? (int)$latest["file_size"] : null,
        "status" => strtoupper((string)($latest["status"] ?? "submitted")),
        "remarks" => $latest["remarks"] ?? null,
        "uploaded_at" => $latest["uploaded_at"] ?? null,
        "reviewed_at" => $latest["reviewed_at"] ?? null,
        "reviewed_by" => isset($latest["reviewed_by"]) && $latest["reviewed_by"] !== null ? (int)$latest["reviewed_by"] : null,
        "preview_url" => $baseUrl . $docPath . "&mode=preview",
        "download_url" => $baseUrl . $docPath . "&mode=download",
      ];
    }

    $requirements[] = [
      "id" => $reqId,
      "code" => $req["requirement_code"],
      "name" => $req["requirement_name"],
      "is_required" => (int)$req["is_required"] === 1,
      "is_system" => (int)$req["is_system"] === 1,
      "submission" => $submissionPayload,
    ];
  }

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
  $verificationRequest = $vrStmt->fetch(PDO::FETCH_ASSOC) ?: null;

  out(200, [
    "ok" => true,
    "user" => [
      "id" => (int)$user["id"],
      "firstname" => $user["firstname"] ?? "",
      "lastname" => $user["lastname"] ?? "",
      "email" => $user["email"] ?? "",
      "username" => $user["username"] ?? "",
      "role" => $user["role"] ?? "citizen",
      "valid" => $user["valid"] ?? null,
      "account_status" => $user["account_status"] ?? "pending",
      "rejected_reason" => $user["rejected_reason"] ?? null,
      "is_email_verified" => (int)($user["is_email_verified"] ?? 0),

      "false_report_count" => (int)($user["false_report_count"] ?? 0),
      "false_alarm_count" => (int)($user["false_alarm_count"] ?? 0),
      "account_flag_status" => $user["account_flag_status"] ?? "none",
      "flagged_reason" => $user["flagged_reason"] ?? null,
      "flagged_at" => $user["flagged_at"] ?? null,
      "suspended_at" => $user["suspended_at"] ?? null,
      "suspension_reason" => $user["suspension_reason"] ?? null,
    ],
    "profile" => $safeProfile,
    "requirements" => $requirements,
    "verification_request" => $verificationRequest,
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}