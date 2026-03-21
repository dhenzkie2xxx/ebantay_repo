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
$reason = station_clean($data["reason"] ?? "");

if ($stationId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid station ID."]);
}

if ($reason === "") {
  auth_out(400, ["ok" => false, "message" => "Resubmission reason is required."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT id, created_by, verification_status
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $oldStatus = $station["verification_status"];

  $pdo->beginTransaction();

  $updStation = $pdo->prepare("
    UPDATE police_stations
    SET
      verification_status = 'resubmission_required',
      reviewed_at = NOW(),
      reviewed_by = ?,
      rejection_reason = ?,
      approved_at = NULL
    WHERE id = ?
  ");
  $updStation->execute([$AUTH_USER["id"], $reason, $stationId]);

  $updUser = $pdo->prepare("
    UPDATE users
    SET
      account_status = 'pending',
      valid = 'unvalid',
      rejected_reason = ?
    WHERE id = ?
  ");
  $updUser->execute([$reason, $station["created_by"]]);

  $history = $pdo->prepare("
    INSERT INTO police_station_verification_history (
      station_id,
      old_status,
      new_status,
      remarks,
      acted_by
    ) VALUES (?, ?, 'resubmission_required', ?, ?)
  ");
  $history->execute([
    $stationId,
    $oldStatus,
    $reason,
    $AUTH_USER["id"]
  ]);

  $pdo->commit();

  auth_out(200, [
    "ok" => true,
    "message" => "Resubmission requested successfully."
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}