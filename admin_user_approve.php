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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

function get_bearer_or_body_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $raw = file_get_contents("php://input");
  if ($raw !== "") {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $bodyToken = trim((string)($json["token"] ?? ""));
      if ($bodyToken !== "") return $bodyToken;
    }
  }

  return "";
}

function get_request_json(): array {
  $raw = file_get_contents("php://input");
  if ($raw === "") return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
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

function get_target_user(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.role,
      u.valid,
      u.account_status,
      up.province,
      up.city_municipality
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
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

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_body_token();
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

  $data = get_request_json();
  $targetUserId = (int)($data["user_id"] ?? 0);

  if ($targetUserId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid user_id"]);
  }

  $targetUser = get_target_user($pdo, $targetUserId);
  if (!$targetUser) {
    out(404, ["ok" => false, "message" => "Citizen user not found"]);
  }

  if (!can_admin_access_user($scope, $targetUser)) {
    out(403, ["ok" => false, "message" => "You do not have access to this user"]);
  }

  $currentStatus = strtolower((string)($targetUser["account_status"] ?? "pending"));
  if ($currentStatus === "verified" || $currentStatus === "active") {
    out(200, [
      "ok" => true,
      "message" => "User is already verified",
      "user" => [
        "id" => (int)$targetUser["id"],
        "firstname" => $targetUser["firstname"],
        "lastname" => $targetUser["lastname"],
        "email" => $targetUser["email"],
        "account_status" => $targetUser["account_status"],
        "valid" => $targetUser["valid"],
      ]
    ]);
  }

  $pdo->beginTransaction();

  $approveStmt = $pdo->prepare("
    UPDATE users
    SET
      valid = 'valid',
      account_status = 'verified',
      approved_by = ?,
      approved_at = NOW(),
      rejected_reason = NULL,
      updated_at = NOW()
    WHERE id = ?
      AND LOWER(role) = 'citizen'
    LIMIT 1
  ");
  $approveStmt->execute([
    (int)$adminUser["id"],
    $targetUserId,
  ]);

  if ($approveStmt->rowCount() < 1) {
    throw new RuntimeException("Failed to update user verification status");
  }

  $latestReqStmt = $pdo->prepare("
    SELECT id
    FROM user_verification_requests
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $latestReqStmt->execute([$targetUserId]);
  $latestReqId = $latestReqStmt->fetchColumn();

  if ($latestReqId) {
    $updateReqStmt = $pdo->prepare("
      UPDATE user_verification_requests
      SET
        status = 'approved',
        reviewed_at = NOW(),
        reviewed_by = ?,
        remarks = COALESCE(remarks, 'Approved by station admin')
      WHERE id = ?
    ");
    $updateReqStmt->execute([
      (int)$adminUser["id"],
      (int)$latestReqId,
    ]);
  }

  $approveDocsStmt = $pdo->prepare("
    UPDATE user_requirement_submissions
    SET
      status = 'approved',
      reviewed_at = NOW(),
      reviewed_by = ?,
      remarks = CASE
        WHEN remarks IS NULL OR remarks = '' THEN 'Approved by station admin'
        ELSE remarks
      END
    WHERE user_id = ?
      AND status = 'submitted'
  ");
  $approveDocsStmt->execute([
    (int)$adminUser["id"],
    $targetUserId,
  ]);

  $notifyStmt = $pdo->prepare("
    INSERT INTO notification_alerts
    (
      user_id,
      type,
      title,
      message,
      severity,
      is_read,
      created_at
    )
    VALUES (?, ?, ?, ?, ?, 0, NOW())
  ");
  $notifyStmt->execute([
    $targetUserId,
    'ACCOUNT_STATUS',
    'Account Verified',
    'Your account has been verified by the station admin. You can now use report and panic features.',
    'LOW'
  ]);

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "User verified successfully",
    "scope" => [
      "role" => $scope["role"],
      "station_id" => $scope["station_id"],
      "station_name" => $scope["station_name"],
      "province" => $scope["province"],
      "city_municipality" => $scope["city_municipality"],
    ],
    "user" => [
      "id" => (int)$targetUser["id"],
      "firstname" => $targetUser["firstname"],
      "lastname" => $targetUser["lastname"],
      "email" => $targetUser["email"],
      "account_status" => "verified",
      "valid" => "valid",
      "approved_by" => (int)$adminUser["id"],
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage(),
  ]);
}