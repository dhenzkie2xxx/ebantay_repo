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

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

$token = trim($data["token"] ?? "");
$level = trim($data["level"] ?? "");
$lat = $data["lat"] ?? null;
$lng = $data["lng"] ?? null;
$accuracy = $data["accuracy"] ?? null;
$deviceTime = trim($data["device_time"] ?? ""); // optional ISO string

if ($token === "" || !in_array($level, ["alert", "urgent"], true) || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing/invalid fields"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, ["ok" => false, "message" => "Coordinates out of range"]);
}

try {
  // Validate token + user
  $q = $pdo->prepare("
    SELECT id, api_token_expires, is_email_verified
    FROM users
    WHERE api_token = ?
    LIMIT 1
  ");
  $q->execute([$token]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) out(401, ["ok" => false, "message" => "Unauthorized"]);

  $exp = !empty($user["api_token_expires"]) ? strtotime($user["api_token_expires"]) : 0;
  if ($exp > 0 && time() > $exp) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  // Optional: block if email not verified (keeps consistent with login.php)
  if ((int)($user["is_email_verified"] ?? 0) !== 1) {
    out(403, ["ok" => false, "message" => "Email not verified"]);
  }

  // Parse device_time safely (avoid 1970 issue)
  $deviceTimeSql = null;
  if ($deviceTime !== "") {
    $ts = strtotime($deviceTime);
    if ($ts !== false) {
      $deviceTimeSql = date("Y-m-d H:i:s", $ts);
    }
  }

  // Insert panic request
  $stmt = $pdo->prepare("
    INSERT INTO panic_requests (user_id, level, lat, lng, accuracy_m, device_time)
    VALUES (?, ?, ?, ?, ?, ?)
  ");

  $stmt->execute([
    (int)$user["id"],
    $level,
    $lat,
    $lng,
    ($accuracy !== null && is_numeric($accuracy)) ? (int)round((float)$accuracy) : null,
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
