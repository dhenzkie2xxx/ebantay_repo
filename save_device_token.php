<?php
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

$body = json_decode(file_get_contents("php://input"), true);
$apiToken = trim($body["token"] ?? "");
$fcmToken = trim($body["fcm_token"] ?? "");
$platform = strtolower(trim($body["platform"] ?? "android"));

if ($apiToken === "" || $fcmToken === "") {
  out(400, ["ok" => false, "message" => "Missing token/fcm_token"]);
}

if (!in_array($platform, ["android", "ios"], true)) {
  $platform = "android";
}

try {
  // Auth like me.php (token + expiry)
  $stmt = $pdo->prepare("
    SELECT id, api_token_expires
    FROM users
    WHERE api_token = ?
    LIMIT 1
  ");
  $stmt->execute([$apiToken]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) out(401, ["ok" => false, "message" => "Unauthorized"]);

  if (!empty($user["api_token_expires"])) {
    $exp = strtotime($user["api_token_expires"]);
    if ($exp > 0 && time() > $exp) out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $userId = (int)$user["id"];

  // Upsert by fcm_token (requires UNIQUE fcm_token)
  $stmt = $pdo->prepare("
    INSERT INTO device_tokens (user_id, fcm_token, platform)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
      user_id = VALUES(user_id),
      platform = VALUES(platform),
      updated_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$userId, $fcmToken, $platform]);

  out(200, ["ok" => true]);

} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}