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

function can_admin_access_user(PDO $pdo, array $adminUser, int $targetUserId): array {
  $role = strtolower((string)($adminUser["role"] ?? ""));

  if ($role === "super_admin") {
    return ["ok" => true];
  }

  $stationStmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.station_name,
      ps.city_municipality,
      ps.province
    FROM police_stations ps
    WHERE ps.user_id = ?
    LIMIT 1
  ");
  $stationStmt->execute([(int)$adminUser["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    return ["ok" => false, "message" => "No police station is linked to this admin account"];
  }

  $scopeProvince = normalize_scope_value($station["province"] ?? null);
  $scopeCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$scopeProvince || !$scopeCity) {
    return ["ok" => false, "message" => "The linked station does not have a complete province/city scope"];
  }

  $userScopeStmt = $pdo->prepare("
    SELECT
      up.province,
      up.city_municipality
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $userScopeStmt->execute([$targetUserId]);
  $userScope = $userScopeStmt->fetch(PDO::FETCH_ASSOC);

  if (!$userScope) {
    return ["ok" => false, "message" => "Citizen user not found"];
  }

  $userProvince = normalize_scope_value($userScope["province"] ?? null);
  $userCity = normalize_scope_value($userScope["city_municipality"] ?? null);

  if (
    !$userProvince ||
    !$userCity ||
    strcasecmp($userProvince, $scopeProvince) !== 0 ||
    strcasecmp($userCity, $scopeCity) !== 0
  ) {
    return ["ok" => false, "message" => "You do not have access to this user"];
  }

  return [
    "ok" => true,
    "scope" => [
      "station_id" => (int)$station["id"],
      "station_name" => $station["station_name"] ?? null,
      "province" => $scopeProvince,
      "city_municipality" => $scopeCity
    ]
  ];
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_query_token();
  if ($token === "") {
    out(401, ["ok" => false, "message" => "Missing token"]);
  }

  $adminUser = auth_get_user_by_token($pdo, $token);
  if (!$adminUser) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($adminUser)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $adminRole = strtolower((string)($adminUser["role"] ?? ""));
  if (!in_array($adminRole, ["admin", "super_admin"], true)) {
    out(403, ["ok" => false, "message" => "Access denied"]);
  }

  $targetUserId = (int)($_GET["id"] ?? 0);
  if ($targetUserId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid user id"]);
  }

  $access = can_admin_access_user($pdo, $adminUser, $targetUserId);
  if (!$access["ok"]) {
    out(403, ["ok" => false, "message" => $access["message"] ?? "Access denied"]);
  }

  // -------------------------------------------------------
  // User + profile
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
      up.created_at AS profile_created_at,
      up.updated_at AS profile_updated_at

    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $stmt->execute([$targetUserId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    out(404, ["ok" => false, "message" => "Citizen user not found"]);
  }

  // -------------------------------------------------------
  // Requirements
  // Baseline/global + station/city/province-scoped dynamic reqs
  // -------------------------------------------------------
  $requirementsSql = "
    SELECT
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
    WHERE r.active = 1
      AND (
        (r.station_id IS NULL AND r.city_municipality IS NULL AND r.province IS NULL)
        OR (r.station_id IS NULL AND LOWER(COALESCE(r.province, '')) = LOWER(?) AND LOWER(COALESCE(r.city_municipality, '')) = LOWER(?))
  ";

  $params = [
    $row["province"] ?? "",
    $row["city_municipality"] ?? "",
  ];

  if ($adminRole !== "super_admin" && isset($access["scope"]["station_id"])) {
    $requirementsSql .= " OR r.station_id = ? ";
    $params[] = (int)$access["scope"]["station_id"];
  }

  $requirementsSql .= ")
    ORDER BY r.is_system DESC, r.requirement_name ASC
  ";

  $reqStmt = $pdo->prepare($requirementsSql);
  $reqStmt->execute($params);
  $requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // -------------------------------------------------------
  // Submissions
  // Return file URLs for preview/download endpoint
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
    ORDER BY s.uploaded_at DESC, s.id DESC
  ");
  $subStmt->execute([$targetUserId]);
  $submissionsRaw = $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $baseUrl = "";
  if (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") {
    $scheme = "https";
  } else {
    $scheme = "http";
  }

  $host = $_SERVER["HTTP_HOST"] ?? "";
  if ($host !== "") {
    $baseUrl = $scheme . "://" . $host;
  }

  $tokenParam = rawurlencode($token);

  $submissions = array_map(function ($s) use ($baseUrl, $tokenParam) {
    $id = (int)$s["id"];
    $path = "/get_user_document.php?id=" . $id . "&token=" . $tokenParam;

    return [
      "id" => $id,
      "user_id" => (int)$s["user_id"],
      "requirement_id" => (int)$s["requirement_id"],
      "requirement_code" => $s["requirement_code"],
      "requirement_name" => $s["requirement_name"],
      "file_name" => $s["file_name"],
      "mime_type" => $s["mime_type"],
      "file_size" => $s["file_size"] !== null ? (int)$s["file_size"] : null,
      "status" => strtoupper((string)($s["status"] ?? "submitted")),
      "remarks" => $s["remarks"],
      "uploaded_at" => $s["uploaded_at"],
      "reviewed_at" => $s["reviewed_at"],
      "reviewed_by" => $s["reviewed_by"] !== null ? (int)$s["reviewed_by"] : null,
      "preview_url" => $baseUrl . $path . "&mode=preview",
      "download_url" => $baseUrl . $path . "&mode=download"
    ];
  }, $submissionsRaw);

  // -------------------------------------------------------
  // Latest verification request
  // -------------------------------------------------------
  $vrStmt = $pdo->prepare("
    SELECT
      v.id,
      v.user_id,
      v.status,
      v.submitted_at,
      v.reviewed_at,
      v.reviewed_by,
      v.remarks
    FROM user_verification_requests v
    WHERE v.user_id = ?
    ORDER BY v.id DESC
    LIMIT 1
  ");
  $vrStmt->execute([$targetUserId]);
  $verificationRequest = $vrStmt->fetch(PDO::FETCH_ASSOC);

  out(200, [
    "ok" => true,
    "scope" => $access["scope"] ?? [
      "role" => $adminRole
    ],
    "user" => [
      "id" => (int)$row["id"],
      "firstname" => $row["firstname"],
      "lastname" => $row["lastname"],
      "email" => $row["email"],
      "username" => $row["username"],
      "role" => $row["role"],
      "valid" => $row["valid"],
      "account_status" => strtolower((string)($row["account_status"] ?? "pending")),
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
      "profile_created_at" => $row["profile_created_at"],
      "profile_updated_at" => $row["profile_updated_at"]
    ],
    "requirements" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "code" => $r["code"],
        "name" => $r["name"],
        "is_required" => (int)$r["is_required"] === 1,
        "is_system" => (int)$r["is_system"] === 1,
        "station_id" => $r["station_id"] !== null ? (int)$r["station_id"] : null,
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"],
        "active" => (int)$r["active"] === 1,
        "created_by" => $r["created_by"] !== null ? (int)$r["created_by"] : null,
        "created_at" => $r["created_at"],
        "updated_at" => $r["updated_at"]
      ];
    }, $requirements),
    "submissions" => $submissions,
    "verification_request" => $verificationRequest ? [
      "id" => (int)$verificationRequest["id"],
      "user_id" => (int)$verificationRequest["user_id"],
      "status" => strtolower((string)($verificationRequest["status"] ?? "pending")),
      "submitted_at" => $verificationRequest["submitted_at"],
      "reviewed_at" => $verificationRequest["reviewed_at"],
      "reviewed_by" => $verificationRequest["reviewed_by"] !== null ? (int)$verificationRequest["reviewed_by"] : null,
      "remarks" => $verificationRequest["remarks"]
    ] : null
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}