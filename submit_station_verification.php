<?php
require_once __DIR__ . "/require_admin_account.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

try {
  $stationStmt = $pdo->prepare("
    SELECT
      id,
      station_name,
      verification_status,
      station_type,
      region,
      province,
      city_municipality,
      barangay,
      sitio,
      street_address,
      full_address,
      lat,
      lng,
      accuracy_m,
      contact_person,
      contact_position,
      contact_mobile,
      contact_landline,
      contact_email,
      operating_hours,
      emergency_contact
    FROM police_stations
    WHERE created_by = ?
    LIMIT 1
  ");
  $stationStmt->execute([$AUTH_USER["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(400, ["ok" => false, "message" => "Please register your station first."]);
  }

  $stationId = (int)$station["id"];
  $status = (string)($station["verification_status"] ?? "");

  if (!in_array($status, station_can_submit_statuses(), true)) {
    auth_out(403, [
      "ok" => false,
      "message" => "This station cannot be submitted in its current status.",
      "verification_status" => $status
    ]);
  }

  $missingStationFields = [];

  $requiredFields = [
    "station_name",
    "station_type",
    "region",
    "city_municipality",
    "full_address",
    "contact_person",
    "operating_hours"
  ];

  foreach ($requiredFields as $field) {
    if (trim((string)($station[$field] ?? "")) === "") {
      $missingStationFields[] = $field;
    }
  }

  $coordError = station_validate_coordinates($station["lat"] ?? null, $station["lng"] ?? null);
  if ($coordError !== null) {
    $missingStationFields[] = "lat";
    $missingStationFields[] = "lng";
  }

  $requiredDocs = station_required_document_types($pdo);

  $docStmt = $pdo->prepare("
    SELECT DISTINCT document_type
    FROM police_station_documents
    WHERE station_id = ?
  ");
  $docStmt->execute([$stationId]);

  $present = [];
  while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
    $docType = trim((string)($row["document_type"] ?? ""));
    if ($docType !== "") {
      $present[] = $docType;
    }
  }

  $missingDocs = array_values(array_diff($requiredDocs, array_unique($present)));

  if (!empty($missingStationFields) || !empty($missingDocs)) {
    auth_out(400, [
      "ok" => false,
      "message" => "Station registration is incomplete.",
      "missing_fields" => $missingStationFields,
      "missing_documents" => $missingDocs
    ]);
  }

  $pdo->beginTransaction();

  $upd = $pdo->prepare("
    UPDATE police_stations
    SET
      verification_status = 'pending',
      submitted_at = NOW(),
      reviewed_at = NULL,
      reviewed_by = NULL,
      rejection_reason = NULL,
      approved_at = NULL
    WHERE id = ?
  ");
  $upd->execute([$stationId]);

  $userUpd = $pdo->prepare("
    UPDATE users
    SET
      account_status = 'pending',
      valid = 'unvalid',
      rejected_reason = NULL
    WHERE id = ?
  ");
  $userUpd->execute([$AUTH_USER["id"]]);

  $history = $pdo->prepare("
    INSERT INTO police_station_verification_history (
      station_id,
      old_status,
      new_status,
      remarks,
      acted_by
    ) VALUES (?, ?, 'pending', ?, ?)
  ");
  $history->execute([
    $stationId,
    $status,
    "Submitted by station admin.",
    $AUTH_USER["id"]
  ]);

  $pdo->commit();

  auth_out(200, [
    "ok" => true,
    "message" => "Station submitted for verification.",
    "station_id" => $stationId,
    "verification_status" => "pending"
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  auth_out(500, [
    "ok" => false,
    "message" => "Server error.",
    "error" => $e->getMessage()
  ]);
}