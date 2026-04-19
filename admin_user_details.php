<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$allowedOrigins = [
  "http://localhost:5173",
  "http://127.0.0.1:5173",
  "https://ebantay.top.gen.in",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit;
}

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

function get_admin_scope(PDO $pdo, array $adminUser): array {
  $role = strtolower((string)($adminUser["role"] ?? ""));

  if ($role === "super_admin") {
    return [
      "ok" => true,
      "role" => "super_admin",
      "station_id" => null,
      "province" => null,
      "city_municipality" => null,
      "station_name" => null,
    ];
  }

  if ($role !== "admin") {
    return ["ok" => false, "message" => "Access denied"];
  }

  $stmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.station_name,
      ps.province,
      ps.city_municipality
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$adminUser["id"]]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    return ["ok" => false, "message" => "No police station is linked to this admin account"];
  }

  $province = normalize_scope_value($station["province"] ?? null);
  $city = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$province || !$city) {
    return ["ok" => false, "message" => "The linked station does not have a complete province/city scope"];
  }

  return [
    "ok" => true,
    "role" => "admin",
    "station_id" => (int)$station["id"],
    "station_name" => $station["station_name"] ?? null,
    "province" => $province,
    "city_municipality" => $city,
  ];
}

function can_admin_access_user(array $scope, array $targetUser): bool {
  if (($scope["role"] ?? "") === "super_admin") {
    return true;
  }

  $targetProvince = normalize_scope_value($targetUser["province"] ?? null);
  $targetCity = normalize_scope_value($targetUser["city_municipality"] ?? null);

  if (!$targetProvince || !$targetCity) {
    return false;
  }

  return
    strcasecmp((string)$scope["province"], (string)$targetProvince) === 0 &&
    strcasecmp((string)$scope["city_municipality"], (string)$targetCity) === 0;
}

function requirement_applies_to_user(PDO $pdo, array $requirement, array $profile): bool {
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

  $scope = get_admin_scope($pdo, $adminUser);
  if (!($scope["ok"] ?? false)) {
    out(403, ["ok" => false, "message" => $scope["message"] ?? "Access denied"]);
  }

  $targetUserId = (int)($_GET["id"] ?? 0);
  if ($targetUserId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid user id"]);
  }

  $userStmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.role,
      u.valid,
      u.account_status,
      u.false_report_count,
      u.false_alarm_count,
      u.account_flag_status,
      u.flagged_at,
      u.flagged_reason,
      u.suspended_at,
      u.suspended_by,
      u.suspension_reason,
      u.approved_by,
      u.approved_at,
      u.rejected_reason,
      u.is_email_verified,
      up.mobile_number,
      up.address_text,
      up.address_lat,
      up.address_lng,
      up.barangay,
      up.city_municipality,
      up.province,
      up.region
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $userStmt->execute([$targetUserId]);
  $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

  if (!$userRow) {
    out(404, ["ok" => false, "message" => "Citizen user not found"]);
  }

  if (!can_admin_access_user($scope, $userRow)) {
    out(403, ["ok" => false, "message" => "You do not have access to this user"]);
  }

  $profile = [
    "user_id" => (int)$userRow["id"],
    "mobile_number" => $userRow["mobile_number"] ?? null,
    "address_text" => $userRow["address_text"] ?? null,
    "address_lat" => $userRow["address_lat"] !== null ? (float)$userRow["address_lat"] : null,
    "address_lng" => $userRow["address_lng"] !== null ? (float)$userRow["address_lng"] : null,
    "barangay" => $userRow["barangay"] ?? null,
    "city_municipality" => $userRow["city_municipality"] ?? null,
    "province" => $userRow["province"] ?? null,
    "region" => $userRow["region"] ?? null,
  ];

  $requirementsStmt = $pdo->query("
    SELECT
      r.id,
      r.requirement_code,
      r.requirement_name,
      r.is_required,
      r.is_system,
      r.station_id,
      r.city_municipality,
      r.province,
      r.active
    FROM user_verification_requirements r
    WHERE r.active = 1
    ORDER BY r.is_system DESC, r.requirement_name ASC
  ");
  $allRequirements = $requirementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $applicableRequirements = [];
  foreach ($allRequirements as $req) {
    if (requirement_applies_to_user($pdo, $req, $profile)) {
      $applicableRequirements[] = $req;
    }
  }

  $submissionStmt = $pdo->prepare("
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
  $submissionStmt->execute([$targetUserId]);
  $submissionRows = $submissionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $latestSubmissionByRequirement = [];
  foreach ($submissionRows as $s) {
    $reqId = (int)$s["requirement_id"];
    if (!isset($latestSubmissionByRequirement[$reqId])) {
      $latestSubmissionByRequirement[$reqId] = $s;
    }
  }

  $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
  $host = $_SERVER["HTTP_HOST"] ?? "";
  $baseUrl = $host !== "" ? $scheme . "://" . $host : "";
  $tokenParam = rawurlencode($token);

  $requirements = [];
  $submissions = [];

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
        "file_size" => $latest["file_size"] !== null ? (int)$latest["file_size"] : null,
        "status" => strtoupper((string)($latest["status"] ?? "submitted")),
        "remarks" => $latest["remarks"] ?? null,
        "uploaded_at" => $latest["uploaded_at"] ?? null,
        "reviewed_at" => $latest["reviewed_at"] ?? null,
        "reviewed_by" => $latest["reviewed_by"] !== null ? (int)$latest["reviewed_by"] : null,
        "preview_url" => $baseUrl . $docPath . "&mode=preview",
        "download_url" => $baseUrl . $docPath . "&mode=download",
      ];

      $submissions[] = [
        "id" => (int)$latest["id"],
        "requirement_id" => (int)$latest["requirement_id"],
        "requirement_code" => $req["requirement_code"],
        "requirement_name" => $req["requirement_name"],
        "file_name" => $latest["file_name"] ?? null,
        "mime_type" => $latest["mime_type"] ?? null,
        "file_size" => $latest["file_size"] !== null ? (int)$latest["file_size"] : null,
        "status" => strtoupper((string)($latest["status"] ?? "submitted")),
        "remarks" => $latest["remarks"] ?? null,
        "uploaded_at" => $latest["uploaded_at"] ?? null,
        "reviewed_at" => $latest["reviewed_at"] ?? null,
        "reviewed_by" => $latest["reviewed_by"] !== null ? (int)$latest["reviewed_by"] : null,
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

  usort($submissions, function ($a, $b) {
    return strcmp((string)$a["requirement_name"], (string)$b["requirement_name"]);
  });

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
  $vrStmt->execute([$targetUserId]);
  $verificationRequest = $vrStmt->fetch(PDO::FETCH_ASSOC) ?: null;

  out(200, [
    "ok" => true,
    "scope" => [
      "role" => $scope["role"],
      "station_id" => $scope["station_id"],
      "station_name" => $scope["station_name"],
      "province" => $scope["province"],
      "city_municipality" => $scope["city_municipality"],
    ],
    "user" => [
      "id" => (int)$userRow["id"],
      "firstname" => $userRow["firstname"],
      "lastname" => $userRow["lastname"],
      "email" => $userRow["email"],
      "username" => $userRow["username"],
      "role" => $userRow["role"],
      "valid" => $userRow["valid"],
      "account_status" => $userRow["account_status"],
      "false_report_count" => (int)($userRow["false_report_count"] ?? 0),
      "false_alarm_count" => (int)($userRow["false_alarm_count"] ?? 0),
      "account_flag_status" => $userRow["account_flag_status"] ?? "none",
      "flagged_at" => $userRow["flagged_at"] ?? null,
      "flagged_reason" => $userRow["flagged_reason"] ?? null,
      "suspended_at" => $userRow["suspended_at"] ?? null,
      "suspended_by" => $userRow["suspended_by"] !== null ? (int)$userRow["suspended_by"] : null,
      "suspension_reason" => $userRow["suspension_reason"] ?? null,
      "approved_by" => $userRow["approved_by"] !== null ? (int)$userRow["approved_by"] : null,
      "approved_at" => $userRow["approved_at"] ?? null,
      "rejected_reason" => $userRow["rejected_reason"] ?? null,
      "is_email_verified" => (int)($userRow["is_email_verified"] ?? 0),
    ],
    "profile" => $profile,
    "requirements" => $requirements,
    "submissions" => $submissions,
    "verification_request" => $verificationRequest,
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage(),
  ]);
}