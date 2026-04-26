<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

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
$action = trim((string)($data["action"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($policeUserId) || (int)$policeUserId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid Police on Field user ID"]);
}

if (!in_array($action, ["disable", "enable"], true)) {
  out(400, ["ok" => false, "message" => "Invalid action"]);
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
      "message" => "Only Station Admin can manage Police on Field accounts."
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT id, station_id, role
    FROM users
    WHERE id = ?
      AND role = 'police_on_field'
      AND station_id = ?
    LIMIT 1
  ");
  $stmt->execute([
    (int)$policeUserId,
    (int)$admin["station_id"]
  ]);

  $police = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$police) {
    out(404, [
      "ok" => false,
      "message" => "Police on Field account not found under your station."
    ]);
  }

  if ($action === "disable") {
    $update = $pdo->prepare("
      UPDATE users
      SET account_status = 'disabled',
          duty_status = 'offline',
          last_seen_at = NOW()
      WHERE id = ?
        AND role = 'police_on_field'
    ");
  } else {
    $update = $pdo->prepare("
      UPDATE users
      SET account_status = 'active',
          valid = 'valid',
          duty_status = 'offline',
          last_seen_at = NOW()
      WHERE id = ?
        AND role = 'police_on_field'
    ");
  }

  $update->execute([(int)$policeUserId]);

  out(200, [
    "ok" => true,
    "message" => $action === "disable"
      ? "Police on Field account disabled."
      : "Police on Field account enabled.",
    "police_user_id" => (int)$policeUserId,
    "action" => $action
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}