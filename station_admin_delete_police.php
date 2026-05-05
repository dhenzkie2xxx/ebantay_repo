<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

$policeUserId = $data["police_user_id"] ?? null;

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($policeUserId) || (int)$policeUserId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid Police on Field user ID"]);
}

try {
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can delete Police on Field accounts."
    ]);
  }

  $policeUserId = (int)$policeUserId;
  $stationId = (int)$admin["station_id"];

  $stmt = $pdo->prepare("
    SELECT
      id,
      firstname,
      lastname,
      email,
      username,
      role,
      station_id,
      valid,
      account_status,
      duty_status,
      account_flag_status,
      created_at
    FROM users
    WHERE id = ?
      AND role = 'police_on_field'
      AND station_id = ?
    LIMIT 1
  ");
  $stmt->execute([$policeUserId, $stationId]);
  $police = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$police) {
    out(404, [
      "ok" => false,
      "message" => "Police on Field account not found under your station."
    ]);
  }

  if ($police["account_status"] !== "disabled") {
    out(409, [
      "ok" => false,
      "message" => "Only disabled Police on Field accounts can be deleted."
    ]);
  }

  $activeAssignmentStmt = $pdo->prepare("
    SELECT id
    FROM responder_assignments
    WHERE assigned_user_id = ?
      AND status <> 'cancelled'
      AND status <> 'resolved'
    LIMIT 1
  ");
  $activeAssignmentStmt->execute([$policeUserId]);

  if ($activeAssignmentStmt->fetch(PDO::FETCH_ASSOC)) {
    out(409, [
      "ok" => false,
      "message" => "This Police on Field account has an active assignment and cannot be deleted."
    ]);
  }

  $pdo->beginTransaction();

  write_audit_log(
    $pdo,
    $admin,
    "POLICE_ACCOUNT_DELETED",
    "user",
    $policeUserId,
    "Station Admin deleted an inactive Police on Field account.",
    [
      "module" => "police_on_field",
      "target_user_id" => $policeUserId,
      "old_values" => [
        "id" => (int)$police["id"],
        "firstname" => $police["firstname"],
        "lastname" => $police["lastname"],
        "email" => $police["email"],
        "username" => $police["username"],
        "role" => $police["role"],
        "station_id" => $police["station_id"] !== null ? (int)$police["station_id"] : null,
        "valid" => $police["valid"],
        "account_status" => $police["account_status"],
        "duty_status" => $police["duty_status"],
        "account_flag_status" => $police["account_flag_status"],
        "created_at" => $police["created_at"]
      ],
      "new_values" => [
        "deleted" => true
      ]
    ]
  );

  $delete = $pdo->prepare("
    DELETE FROM users
    WHERE id = ?
      AND role = 'police_on_field'
      AND station_id = ?
      AND account_status = 'disabled'
  ");
  $delete->execute([$policeUserId, $stationId]);

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Police on Field account deleted successfully.",
    "police_user_id" => $policeUserId
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}