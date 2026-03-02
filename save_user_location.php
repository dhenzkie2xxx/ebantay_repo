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
$lat = isset($body["lat"]) ? (float)$body["lat"] : null;
$lng = isset($body["lng"]) ? (float)$body["lng"] : null;
$accuracy = isset($body["accuracy"]) ? (int)$body["accuracy"] : null;

$riskCategory = trim($body["risk_category"] ?? "");
$nearestM = isset($body["nearest_m"]) ? (int)$body["nearest_m"] : null;

$pointLat = isset($body["point_lat"]) ? (float)$body["point_lat"] : null;
$pointLng = isset($body["point_lng"]) ? (float)$body["point_lng"] : null;

if ($apiToken === "" || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing token/lat/lng"]);
}

try {
  // Auth user by api_token (same logic you use elsewhere)
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

  $ins = $pdo->prepare("
    INSERT INTO user_locations
      (user_id, lat, lng, accuracy_m, risk_category, nearest_m, point_lat, point_lng)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $ins->execute([
    $userId,
    $lat,
    $lng,
    $accuracy,
    ($riskCategory !== "" ? $riskCategory : null),
    $nearestM,
    $pointLat,
    $pointLng
  ]);

  out(200, ["ok" => true]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}