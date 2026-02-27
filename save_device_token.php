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
$apiToken = trim($body["token"] ?? "");        // your users.api_token
$fcmToken = trim($body["fcm_token"] ?? "");
$platform = strtolower(trim($body["platform"] ?? "android"));

if ($apiToken === "" || $fcmToken === "") {
  out(400, ["ok" => false, "message" => "Missing token/fcm_token"]);
}

if (!in_array($platform, ["android", "ios"], true)) {
  $platform = "android";
}

try {
  // ✅ AUTH: api_token must match AND not expired AND user valid
  $stmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE api_token = ?
      AND (api_token_expires IS NULL OR api_token_expires > NOW())
      AND valid = 'valid'
    LIMIT 1
  ");
  $stmt->execute([$apiToken]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized or token expired"]);
  }

  $userId = (int)$user["id"];

  // Upsert token
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