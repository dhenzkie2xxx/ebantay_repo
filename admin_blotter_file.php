<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";
require_once __DIR__ . "/location_resolver.php";

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

$incidentId = (int)($data["incident_id"] ?? 0);
$title = trim((string)($data["title"] ?? ""));
$crimeTypeId = (int)($data["crime_type_id"] ?? 0);
$incomingIncidentType = trim((string)($data["incident_type"] ?? ""));
$incomingCrimeCategory = strtoupper(trim((string)($data["crime_category"] ?? "OTHER")));
if (!in_array($incomingCrimeCategory, ["INDEX", "NON_INDEX", "SPECIAL_LAW", "OTHER"], true)) {
  $incomingCrimeCategory = "OTHER";
}
$narrative = trim((string)($data["narrative"] ?? ""));

$incomingReportSource = strtolower(trim((string)($data["report_source"] ?? "")));
$incomingReportChannel = strtolower(trim((string)($data["report_channel"] ?? "")));

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

$lat = isset($data["lat"]) && $data["lat"] !== "" ? (float)$data["lat"] : null;
$lng = isset($data["lng"]) && $data["lng"] !== "" ? (float)$data["lng"] : null;

$persons = $data["persons"] ?? [];
$properties = $data["properties"] ?? [];
$officers = $data["officers"] ?? [];

$allowedCase = ["OPEN", "CLEARED", "SOLVED", "CLOSED", "UNFOUNDED"];
$allowedSources = ["walk_in", "hotline", "police_encoder", "other"];
$allowedChannels = ["station", "phone", "radio", "other"];
$allowedPersonRoles = ["REPORTING_PERSON","VICTIM","SUSPECT","WITNESS","GUARDIAN","OFFICER_SUBJECT"];
$allowedSuspectStatus = ["UNKNOWN","AT_LARGE","ARRESTED","SURRENDERED","DETAINED"];
$allowedPropertyRoles = ["STOLEN","DAMAGED","RECOVERED","SEIZED","LOST"];
$allowedOfficerRoles = ["ADMINISTERING_OFFICER","DUTY_INVESTIGATOR","ASSISTING_OFFICER","DESK_OFFICER","ENCODER"];

if (!in_array($caseStatus, $allowedCase, true)) {
  $caseStatus = "OPEN";
}

if ($incidentId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing incident_id"]);
  exit;
}

if ($title === "" || ($crimeTypeId <= 0 && $incomingIncidentType === "") || $narrative === "" || $barangay === "" || $cityMunicipality === "" || $province === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing required fields"]);
  exit;
}

if ($lat === null || $lng === null) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Pin the incident location on the map"]);
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

function norm_text($v): ?string {
  $v = trim((string)($v ?? ""));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v === "" ? null : $v;
}

function current_admin_scope(PDO $pdo, array $AUTH_USER): array {
  $role = (string)($AUTH_USER["role"] ?? "");
  $isSuperAdmin = $role === "super_admin";

  if ($isSuperAdmin) {
    return [
      "is_super_admin" => true,
      "province" => null,
      "city_municipality" => null
    ];
  }

  $stationProvince = norm_text($AUTH_USER["station_province"] ?? null);
  $stationCity = norm_text($AUTH_USER["station_city_municipality"] ?? null);
  $stationRegion = norm_text($AUTH_USER["station_region"] ?? null);

  $canon = canonicalize_scope($pdo, $stationRegion, $stationProvince, $stationCity);

  if (empty($canon["ok"])) {
    throw new Exception("Unable to resolve your station city/municipality scope.");
  }

  return [
    "is_super_admin" => false,
    "province" => $canon["province"],
    "city_municipality" => $canon["city_municipality"]
  ];
}

function generate_blotter_number(PDO $pdo): string {
  $year = gmdate("Y");

  $stmt = $pdo->prepare("
    SELECT MAX(CAST(SUBSTRING_INDEX(blotter_entry_number, '-', -1) AS UNSIGNED)) AS max_seq
    FROM incident_reports
    WHERE blotter_entry_number LIKE ?
    FOR UPDATE
  ");
  $stmt->execute(["BLT-{$year}-%"]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $next = (int)($row["max_seq"] ?? 0) + 1;
  return sprintf("BLT-%s-%06d", $year, $next);
}

function generate_irf_number(PDO $pdo): string {
  $year = gmdate("Y");

  $stmt = $pdo->prepare("
    SELECT MAX(CAST(SUBSTRING_INDEX(irf_entry_number, '-', -1) AS UNSIGNED)) AS max_seq
    FROM incident_reports
    WHERE irf_entry_number LIKE ?
    FOR UPDATE
  ");
  $stmt->execute(["IRF-{$year}-%"]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $next = (int)($row["max_seq"] ?? 0) + 1;
  return sprintf("IRF-%s-%06d", $year, $next);
}

try {
  $pdo->beginTransaction();

  $scope = current_admin_scope($pdo, $AUTH_USER);

  $scopeSql = "";
  $scopeParams = [];

  if (!$scope["is_super_admin"]) {
    $scopeSql = "
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ";
    $scopeParams[] = $scope["province"];
    $scopeParams[] = $scope["city_municipality"];
  }

  $sel = $pdo->prepare("
    SELECT
      incident_phase,
      case_status,
      verification_status,
      report_source,
      report_channel,
      blotter_entry_number,
      irf_entry_number,
      province,
      city_municipality
    FROM incident_reports
    WHERE id = ?
    $scopeSql
    LIMIT 1
  ");

  $sel->execute(array_merge([$incidentId], $scopeParams));
  $old = $sel->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    throw new Exception("Incident not found or outside your station city/municipality.");
  }

  $crime = null;

if ($crimeTypeId > 0) {
  $crimeStmt = $pdo->prepare("
    SELECT id, crime_name, crime_category, focus_crime_code, ciras_offense_code
    FROM crime_types
    WHERE id = ? AND is_active = 1
    LIMIT 1
  ");
  $crimeStmt->execute([$crimeTypeId]);
  $crime = $crimeStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$crime && $incomingIncidentType !== "") {
  $crimeStmt = $pdo->prepare("
    SELECT id, crime_name, crime_category, focus_crime_code, ciras_offense_code
    FROM crime_types
    WHERE LOWER(TRIM(crime_name)) = LOWER(TRIM(?))
      AND is_active = 1
    LIMIT 1
  ");
  $crimeStmt->execute([$incomingIncidentType]);
  $crime = $crimeStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$crime) {
  $crime = [
    "id" => null,
    "crime_name" => $incomingIncidentType,
    "crime_category" => $incomingCrimeCategory ?: "OTHER",
    "focus_crime_code" => null,
    "ciras_offense_code" => null
  ];
}

  $isMobileOrigin = strtolower((string)$old["report_source"]) === "mobile_app";
  if ($isMobileOrigin) {
    $reportSource = "mobile_app";
    $reportChannel = "mobile";
  } else {
    $reportSource = in_array($incomingReportSource, $allowedSources, true) ? $incomingReportSource : "walk_in";
    $reportChannel = in_array($incomingReportChannel, $allowedChannels, true) ? $incomingReportChannel : "station";
  }

  $blotterEntryNumber = trim((string)($old["blotter_entry_number"] ?? ""));
  if ($blotterEntryNumber === "") {
    $blotterEntryNumber = generate_blotter_number($pdo);
  }

  if ($irfEntryNumber === null || trim((string)$irfEntryNumber) === "") {
    $existingIrf = trim((string)($old["irf_entry_number"] ?? ""));
    $irfEntryNumber = $existingIrf !== "" ? $existingIrf : generate_irf_number($pdo);
  }

  $upd = $pdo->prepare("
    UPDATE incident_reports
    SET
      blotter_entry_number = ?,
      irf_entry_number = ?,
      report_source = ?,
      report_channel = ?,
      crime_type_id = ?,
      incident_type = ?,
      crime_category = ?,
      focus_crime_code = ?,
      ciras_offense_code = ?,
      title = ?,
      narrative = ?,
      lat = ?,
      lng = ?,
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
    $reportSource,
    $reportChannel,
    $crime["id"] !== null ? (int)$crime["id"] : null,
    $crime["crime_name"],
    $crime["crime_category"],
    $crime["focus_crime_code"],
    $crime["ciras_offense_code"],
    $title,
    $narrative,
    $lat,
    $lng,
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

  $pdo->prepare("DELETE FROM incident_persons WHERE incident_id = ?")->execute([$incidentId]);
  $pdo->prepare("DELETE FROM incident_properties WHERE incident_id = ?")->execute([$incidentId]);
  $pdo->prepare("DELETE FROM incident_officers WHERE incident_id = ?")->execute([$incidentId]);

  $personIns = $pdo->prepare("
    INSERT INTO incident_persons
    (
      incident_id,
      person_role,
      family_name,
      first_name,
      middle_name,
      nickname,
      sex_gender,
      civil_status,
      birth_date,
      age,
      mobile_phone,
      email_address,
      current_address,
      current_sitio,
      current_barangay,
      current_city,
      current_province,
      occupation,
      relation_to_victim,
      suspect_status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ($persons as $p) {
    $role = strtoupper(trim((string)($p["person_role"] ?? "")));
    if (!in_array($role, $allowedPersonRoles, true)) continue;

    $suspectStatus = strtoupper(trim((string)($p["suspect_status"] ?? "UNKNOWN")));
    if (!in_array($suspectStatus, $allowedSuspectStatus, true)) {
      $suspectStatus = "UNKNOWN";
    }

    $familyName = trim((string)($p["family_name"] ?? ""));
    $firstName = trim((string)($p["first_name"] ?? ""));
    if ($familyName === "" && $firstName === "") continue;

    $personIns->execute([
      $incidentId,
      $role,
      $familyName !== "" ? $familyName : null,
      $firstName !== "" ? $firstName : null,
      trim((string)($p["middle_name"] ?? "")) ?: null,
      trim((string)($p["nickname"] ?? "")) ?: null,
      trim((string)($p["sex_gender"] ?? "")) ?: null,
      trim((string)($p["civil_status"] ?? "")) ?: null,
      trim((string)($p["birth_date"] ?? "")) ?: null,
      ($p["age"] ?? "") !== "" ? (int)$p["age"] : null,
      trim((string)($p["mobile_phone"] ?? "")) ?: null,
      trim((string)($p["email_address"] ?? "")) ?: null,
      trim((string)($p["current_address"] ?? "")) ?: null,
      trim((string)($p["current_sitio"] ?? "")) ?: null,
      trim((string)($p["current_barangay"] ?? "")) ?: null,
      trim((string)($p["current_city"] ?? "")) ?: null,
      trim((string)($p["current_province"] ?? "")) ?: null,
      trim((string)($p["occupation"] ?? "")) ?: null,
      trim((string)($p["relation_to_victim"] ?? "")) ?: null,
      $suspectStatus
    ]);
  }

  $propertyIns = $pdo->prepare("
    INSERT INTO incident_properties
    (
      incident_id,
      property_role,
      property_type,
      description,
      quantity,
      estimated_value,
      recovered_flag,
      serial_number,
      plate_number
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ($properties as $p) {
    $role = strtoupper(trim((string)($p["property_role"] ?? "STOLEN")));
    if (!in_array($role, $allowedPropertyRoles, true)) continue;

    $propertyType = trim((string)($p["property_type"] ?? ""));
    if ($propertyType === "") continue;

    $propertyIns->execute([
      $incidentId,
      $role,
      $propertyType,
      trim((string)($p["description"] ?? "")) ?: null,
      max(1, (int)($p["quantity"] ?? 1)),
      ($p["estimated_value"] ?? "") !== "" ? (float)$p["estimated_value"] : null,
      (int)($p["recovered_flag"] ?? 0) ? 1 : 0,
      trim((string)($p["serial_number"] ?? "")) ?: null,
      trim((string)($p["plate_number"] ?? "")) ?: null
    ]);
  }

  $officerIns = $pdo->prepare("
    INSERT INTO incident_officers
    (
      incident_id,
      officer_role,
      rank_title,
      full_name,
      designation,
      police_station,
      mobile_phone
    )
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ($officers as $o) {
    $role = strtoupper(trim((string)($o["officer_role"] ?? "")));
    if (!in_array($role, $allowedOfficerRoles, true)) continue;

    $fullName = trim((string)($o["full_name"] ?? ""));
    if ($fullName === "") continue;

    $officerIns->execute([
      $incidentId,
      $role,
      trim((string)($o["rank_title"] ?? "")) ?: null,
      $fullName,
      trim((string)($o["designation"] ?? "")) ?: null,
      trim((string)($o["police_station"] ?? "")) ?: null,
      trim((string)($o["mobile_phone"] ?? "")) ?: null
    ]);
  }

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

  recalc_hotspots_after_incident_save($pdo, $incidentId);

  $alertResult = ["created" => 0, "targets" => []];
  if (strtoupper((string)$old["incident_phase"]) !== "BLOTTERED") {
    $alertResult = queue_incident_hotspot_alerts($pdo, $incidentId);
  }

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Blotter filed successfully",
    "blotter_entry_number" => $blotterEntryNumber,
    "irf_entry_number" => $irfEntryNumber,
    "alert_created_count" => $alertResult["created"] ?? 0
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