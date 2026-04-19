<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/user_flag_helpers.php";

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
    ];
  }

  if ($role !== "admin") {
    return ["ok" => false, "message" => "Access denied"];
  }

  $stmt = $pdo->prepare("
    SELECT ps.province, ps.city_municipality
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$adminUser["id"]]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    return ["ok" => false, "message" => "No linked station found"];
  }

  return [
    "ok" => true,
    "role" => "admin",
    "province" => normalize_scope_value($station["province"] ?? null),
    "city_municipality" => normalize_scope_value($station["city_municipality"] ?? null),
  ];
}

function can_admin_access_user(array $scope, array $targetUser): bool {
  if (($scope["role"] ?? "") === "super_admin") return true;

  return
    strcasecmp((string)$scope["province"], (string)$targetUser["province"]) === 0 &&
    strcasecmp((string)$scope["city_municipality"], (string)$targetUser["city_municipality"]) === 0;
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_body_token();
  if ($token === "") out(401, ["ok" => false, "message" => "Missing token"]);

  $adminUser = auth_get_user_by_token($pdo, $token);
  if (!$adminUser) out(401, ["ok" => false, "message" => "Unauthorized"]);
  if (auth_check_token_expired($adminUser)) out(401, ["ok" => false, "message" => "Token expired"]);

  $scope = get_admin_scope($pdo, $adminUser);
  if (!($scope["ok"] ?? false)) out(403, ["ok" => false, "message" => $scope["message"]]);

  $data = get_request_json();
  $userId = (int)($data["user_id"] ?? 0);
  $action = strtolower(trim((string)($data["action"] ?? "suspend")));
  $reason = trim((string)($data["reason"] ?? ""));

  if ($userId <= 0) out(400, ["ok" => false, "message" => "Invalid user_id"]);
  if (!in_array($action, ["suspend", "unsuspend"], true)) {
    out(400, ["ok" => false, "message" => "Invalid action"]);
  }
  if ($reason === "") out(400, ["ok" => false, "message" => "Reason is required"]);

  $targetStmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.account_flag_status,
      up.province,
      up.city_municipality
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $targetStmt->execute([$userId]);
  $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

  if (!$target) out(404, ["ok" => false, "message" => "Citizen not found"]);
  if (!can_admin_access_user($scope, $target)) {
    out(403, ["ok" => false, "message" => "Access denied"]);
  }

  $oldStatus = strtolower((string)($target["account_flag_status"] ?? "none"));
  $newStatus = $action === "suspend" ? "suspended" : "none";

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    UPDATE users
    SET
      account_flag_status = ?,
      suspended_at = CASE WHEN ? = 'suspended' THEN NOW() ELSE NULL END,
      suspended_by = CASE WHEN ? = 'suspended' THEN ? ELSE NULL END,
      suspension_reason = CASE WHEN ? = 'suspended' THEN ? ELSE NULL END,
      flagged_reason = CASE WHEN ? = 'none' THEN NULL ELSE flagged_reason END,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([
    $newStatus,
    $newStatus,
    $newStatus,
    (int)$adminUser["id"],
    $newStatus,
    $reason,
    $newStatus,
    $userId,
  ]);

  insert_user_flag_audit(
    $pdo,
    $userId,
    'manual_admin',
    null,
    $action === "suspend" ? 'suspended' : 'unsuspended',
    0,
    0,
    0,
    0,
    $oldStatus,
    $newStatus,
    $reason,
    (int)$adminUser["id"]
  );

  queue_user_alert(
    $pdo,
    $userId,
    'ACCOUNT_STATUS',
    $action === "suspend" ? 'Account Suspended' : 'Account Restored',
    $reason,
    'HIGH'
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => $action === "suspend" ? "User suspended successfully" : "User restored successfully",
    "user" => [
      "id" => (int)$target["id"],
      "account_flag_status" => $newStatus,
      "suspension_reason" => $action === "suspend" ? $reason : null,
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}