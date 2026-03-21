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
$remarks = station_nullable_string($data["remarks"] ?? null);

if ($stationId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid station ID."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.created_by,
      ps.station_name,
      ps.verification_status
    FROM police_stations ps
    WHERE ps.id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $oldStatus = $station["verification_status"];
  if ($oldStatus === "approved") {
    auth_out(400, ["ok" => false, "message" => "Station is already approved."]);
  }

  $docStmt = $pdo->prepare("
    SELECT document_type
    FROM police_station_documents
    WHERE station_id = ?
      AND is_current = 1
  ");
  $docStmt->execute([$stationId]);

  $present = [];
  while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
    $present[] = $row["document_type"];
  }

  $missingDocs = array_values(array_diff(
    station_required_document_types(),
    array_unique($present)
  ));

  if (!empty($missingDocs)) {
    auth_out(400, [
      "ok" => false,
      "message" => "Required station documents are missing.",
      "missing_documents" => $missingDocs
    ]);
  }

  $pdo->beginTransaction();

  $updStation = $pdo->prepare("
    UPDATE police_stations
    SET
      verification_status = 'approved',
      reviewed_at = NOW(),
      reviewed_by = ?,
      rejection_reason = NULL,
      approved_at = NOW(),
      is_active = 1
    WHERE id = ?
  ");
  $updStation->execute([$AUTH_USER["id"], $stationId]);

  $updUser = $pdo->prepare("
    UPDATE users
    SET
      account_status = 'active',
      valid = 'valid',
      approved_by = ?,
      approved_at = NOW(),
      rejected_reason = NULL
    WHERE id = ?
  ");
  $updUser->execute([$AUTH_USER["id"], $station["created_by"]]);

  $history = $pdo->prepare("
    INSERT INTO police_station_verification_history (
      station_id,
      old_status,
      new_status,
      remarks,
      acted_by
    ) VALUES (?, ?, 'approved', ?, ?)
  ");
  $history->execute([
    $stationId,
    $oldStatus,
    $remarks ?: "Station approved by super admin.",
    $AUTH_USER["id"]
  ]);

  $pdo->commit();

  auth_out(200, [
    "ok" => true,
    "message" => "Station approved successfully."
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}