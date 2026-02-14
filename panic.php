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

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$token = trim($data["token"] ?? "");
$level = trim($data["level"] ?? "");
$lat = $data["lat"] ?? null;
$lng = $data["lng"] ?? null;
$accuracy = $data["accuracy"] ?? null;
$deviceTime = trim($data["device_time"] ?? ""); // optional

if ($token === "" || !in_array($level, ["alert", "urgent"], true) || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing/invalid fields"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

try {
  // Validate token
  $q = $pdo->prepare("SELECT id, api_token_expires FROM users WHERE api_token = ? LIMIT 1");
  $q->execute([$token]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) out(401, ["ok" => false, "message" => "Unauthorized"]);

  $exp = $user["api_token_expires"] ? strtotime($user["api_token_expires"]) : 0;
  if ($exp > 0 && time() > $exp) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  // Insert panic request
  $stmt = $pdo->prepare("
    INSERT INTO panic_requests (user_id, level, lat, lng, accuracy_m, device_time)
    VALUES (?, ?, ?, ?, ?, ?)
  ");

  $deviceTimeSql = $deviceTime !== "" ? date("Y-m-d H:i:s", strtotime($deviceTime)) : null;

  $stmt->execute([
    $user["id"],
    $level,
    (float)$lat,
    (float)$lng,
    $accuracy !== null && is_numeric($accuracy) ? (int)round($accuracy) : null,
    $deviceTimeSql
  ]);

  out(200, [
    "ok" => true,
    "message" => "Panic received",
    "id" => (int)$pdo->lastInsertId(),
    "level" => $level
  ]);

} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}
