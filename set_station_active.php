<?php
require_once __DIR__ . "/require_super_admin.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$stationId = (int)($data["station_id"] ?? 0);
$isActive = isset($data["is_active"]) ? (int)$data["is_active"] : -1;

if ($stationId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid station ID."]);
}

if ($isActive !== 0 && $isActive !== 1) {
  auth_out(400, ["ok" => false, "message" => "Invalid is_active value."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT id, verification_status
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $upd = $pdo->prepare("
    UPDATE police_stations
    SET is_active = ?
    WHERE id = ?
  ");
  $upd->execute([$isActive, $stationId]);

  auth_out(200, [
    "ok" => true,
    "message" => $isActive ? "Station activated successfully." : "Station deactivated successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}