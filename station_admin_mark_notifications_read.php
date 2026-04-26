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

if ($token === "") {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  if (is_array($data)) {
    $token = trim($data["token"] ?? "");
  }
}

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
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
      "message" => "Only Station Admin can mark notifications as read."
    ]);
  }

  $stmt = $pdo->prepare("
    UPDATE notification_alerts
    SET is_read = 1
    WHERE user_id = ?
      AND is_read = 0
  ");

  $stmt->execute([(int)$admin["id"]]);

  out(200, [
    "ok" => true,
    "message" => "Notifications marked as read.",
    "updated" => $stmt->rowCount()
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}