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
$lat = $body["lat"] ?? null;
$lng = $body["lng"] ?? null;
$accuracy = $body["accuracy"] ?? null;

if ($apiToken === "" || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing token/lat/lng"]);
}

$lat = (float)$lat;
$lng = (float)$lng;
$accuracy = $accuracy === null ? null : (float)$accuracy;

if (!is_finite($lat) || !is_finite($lng)) {
  out(400, ["ok" => false, "message" => "Invalid lat/lng"]);
}

try {
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
  if (!$user) out(401, ["ok" => false, "message" => "Unauthorized or token expired"]);

  $userId = (int)$user["id"];

  $stmt = $pdo->prepare("
    INSERT INTO user_locations (user_id, lat, lng, accuracy)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      lat = VALUES(lat),
      lng = VALUES(lng),
      accuracy = VALUES(accuracy),
      updated_at = CURRENT_TIMESTAMP
  ");
  $stmt->execute([$userId, $lat, $lng, $accuracy]);

  out(200, ["ok" => true]);

} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}