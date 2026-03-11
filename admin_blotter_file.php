<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

$incidentId = (int)($data["incident_id"] ?? 0);
$blotterEntryNumber = trim((string)($data["blotter_entry_number"] ?? ""));
$irfEntryNumber = trim((string)($data["irf_entry_number"] ?? ""));
$notes = trim((string)($data["admin_notes"] ?? ""));

$hasKnownSuspect = (int)($data["has_known_suspect"] ?? 0) ? 1 : 0;
$suspectCount = max(0, (int)($data["suspect_count"] ?? 0));
$victimCount = max(0, (int)($data["victim_count"] ?? 0));
$witnessCount = max(0, (int)($data["witness_count"] ?? 0));
$propertyLossFlag = (int)($data["property_loss_flag"] ?? 0) ? 1 : 0;
$estimatedDamageValue = $data["estimated_damage_value"] ?? null;

$dateIncidentFrom = trim((string)($data["date_incident_from"] ?? ""));
$dateIncidentTo = trim((string)($data["date_incident_to"] ?? ""));
$placeOfIncident = trim((string)($data["place_of_incident"] ?? ""));
$sitio = trim((string)($data["sitio"] ?? ""));
$barangay = trim((string)($data["barangay"] ?? ""));
$cityMunicipality = trim((string)($data["city_municipality"] ?? ""));
$province = trim((string)($data["province"] ?? ""));
$region = trim((string)($data["region"] ?? ""));
$locationType = trim((string)($data["location_type"] ?? ""));
$caseStatus = strtoupper(trim((string)($data["case_status"] ?? "OPEN")));

$allowedCase = ["OPEN", "CLEARED", "SOLVED", "CLOSED", "UNFOUNDED"];
if (!in_array($caseStatus, $allowedCase, true)) {
  $caseStatus = "OPEN";
}

if ($incidentId <= 0 || $blotterEntryNumber === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing required fields"]);
  exit;
}

if ($estimatedDamageValue === "" || $estimatedDamageValue === null) {
  $estimatedDamageValue = null;
} else {
  $estimatedDamageValue = (float)$estimatedDamageValue;
}

$dateIncidentFrom = $dateIncidentFrom !== "" ? $dateIncidentFrom : null;
$dateIncidentTo = $dateIncidentTo !== "" ? $dateIncidentTo : null;
$irfEntryNumber = $irfEntryNumber !== "" ? $irfEntryNumber : null;
$placeOfIncident = $placeOfIncident !== "" ? $placeOfIncident : null;
$sitio = $sitio !== "" ? $sitio : null;
$barangay = $barangay !== "" ? $barangay : null;
$cityMunicipality = $cityMunicipality !== "" ? $cityMunicipality : null;
$province = $province !== "" ? $province : null;
$region = $region !== "" ? $region : null;
$locationType = $locationType !== "" ? $locationType : null;

$adminId = (int)($AUTH_USER["id"] ?? 0);

try {
  $pdo->beginTransaction();

  $sel = $pdo->prepare("
    SELECT incident_phase, case_status, verification_status
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $sel->execute([$incidentId]);
  $old = $sel->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    throw new Exception("Incident not found");
  }

  $upd = $pdo->prepare("
    UPDATE incident_reports
    SET
      blotter_entry_number = ?,
      irf_entry_number = ?,
      incident_phase = 'BLOTTERED',
      case_status = ?,
      reviewed_by = ?,
      reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()),
      has_known_suspect = ?,
      suspect_count = ?,
      victim_count = ?,
      witness_count = ?,
      property_loss_flag = ?,
      estimated_damage_value = ?,
      date_incident_from = COALESCE(?, date_incident_from),
      date_incident_to = ?,
      place_of_incident = ?,
      sitio = ?,
      barangay = COALESCE(?, barangay),
      city_municipality = COALESCE(?, city_municipality),
      province = COALESCE(?, province),
      region = ?,
      location_type = ?,
      admin_notes = ?
    WHERE id = ?
  ");
  $upd->execute([
    $blotterEntryNumber,
    $irfEntryNumber,
    $caseStatus,
    $adminId,
    $hasKnownSuspect,
    $suspectCount,
    $victimCount,
    $witnessCount,
    $propertyLossFlag,
    $estimatedDamageValue,
    $dateIncidentFrom,
    $dateIncidentTo,
    $placeOfIncident,
    $sitio,
    $barangay,
    $cityMunicipality,
    $province,
    $region,
    $locationType,
    $notes,
    $incidentId
  ]);

  $hist = $pdo->prepare("
    INSERT INTO incident_status_history
    (
      incident_id,
      old_phase,
      new_phase,
      old_case_status,
      new_case_status,
      old_verification_status,
      new_verification_status,
      remarks,
      changed_by
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $hist->execute([
    $incidentId,
    $old["incident_phase"],
    "BLOTTERED",
    $old["case_status"],
    $caseStatus,
    $old["verification_status"],
    $old["verification_status"],
    "Blotter filed. " . $notes,
    $adminId
  ]);

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Blotter filed successfully"
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}