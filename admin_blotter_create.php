<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";
require_once __DIR__ . "/location_resolver.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

$title = trim((string)($data["title"] ?? ""));
$crimeTypeId = (int)($data["crime_type_id"] ?? 0);
$narrative = trim((string)($data["narrative"] ?? ""));

$reportSource = strtolower(trim((string)($data["report_source"] ?? "walk_in")));
$reportChannel = strtolower(trim((string)($data["report_channel"] ?? "station")));

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

$persons = is_array($data["persons"] ?? null) ? $data["persons"] : [];
$properties = is_array($data["properties"] ?? null) ? $data["properties"] : [];
$officers = is_array($data["officers"] ?? null) ? $data["officers"] : [];

$allowedCase = ["OPEN", "RESOLVED", "CLEARED", "SOLVED", "CLOSED", "UNFOUNDED"];
$allowedSources = ["walk_in", "hotline", "police_encoder", "other"];
$allowedChannels = ["station", "phone", "radio", "other"];
$allowedPersonRoles = ["REPORTING_PERSON", "VICTIM", "SUSPECT", "WITNESS", "GUARDIAN", "OFFICER_SUBJECT"];
$allowedSuspectStatus = ["UNKNOWN", "AT_LARGE", "ARRESTED", "SURRENDERED", "DETAINED"];
$allowedPropertyRoles = ["STOLEN", "DAMAGED", "RECOVERED", "SEIZED", "LOST"];
$allowedOfficerRoles = ["ADMINISTERING_OFFICER", "DUTY_INVESTIGATOR", "ASSISTING_OFFICER", "DESK_OFFICER", "ENCODER"];

if (!in_array($caseStatus, $allowedCase, true)) $caseStatus = "OPEN";
if (!in_array($reportSource, $allowedSources, true)) $reportSource = "walk_in";
if (!in_array($reportChannel, $allowedChannels, true)) $reportChannel = "station";

if ($title === "" || $crimeTypeId <= 0 || $narrative === "" || $barangay === "" || $cityMunicipality === "" || $province === "") {
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

$canon = canonicalize_scope($pdo, $region, $province, $cityMunicipality);

if (!$canon["ok"]) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => $canon["message"]
  ]);
  exit;
}

$region = $canon["region"] ?? $region;
$province = $canon["province"];
$cityMunicipality = $canon["city_municipality"];

try {
  $adminScope = current_admin_scope($pdo, $AUTH_USER);

  if (!$adminScope["is_super_admin"]) {
    if (
      strtolower(trim($province)) !== strtolower(trim($adminScope["province"])) ||
      strtolower(trim($cityMunicipality)) !== strtolower(trim($adminScope["city_municipality"]))
    ) {
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "message" => "You can only create blotter records within your assigned station city/municipality."
      ]);
      exit;
    }
  }
} catch (Throwable $e) {
  http_response_code(403);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage()
  ]);
  exit;
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

  $crimeStmt = $pdo->prepare("
    SELECT
      id,
      crime_name,
      crime_category,
      focus_crime_code,
      ciras_offense_code
    FROM crime_types
    WHERE id = ?
    LIMIT 1
  ");
  $crimeStmt->execute([$crimeTypeId]);
  $crime = $crimeStmt->fetch(PDO::FETCH_ASSOC);

  if (!$crime) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Crime type not found"]);
    exit;
  }

  $blotterEntryNumber = generate_blotter_number($pdo);

  if ($irfEntryNumber === null || trim((string)$irfEntryNumber) === "") {
    $irfEntryNumber = generate_irf_number($pdo);
  }

  $incidentCode = "INC-" . date("Ymd") . "-" . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

  $insert = $pdo->prepare("
    INSERT INTO incident_reports
    (
      incident_code,
      reporter_user_id,
      assigned_station_id,
      blotter_entry_number,
      irf_entry_number,
      report_source,
      report_channel,
      crime_type_id,
      incident_type,
      crime_category,
      focus_crime_code,
      ciras_offense_code,
      title,
      narrative,
      date_incident_from,
      date_incident_to,
      place_of_incident,
      sitio,
      barangay,
      city_municipality,
      province,
      region,
      lat,
      lng,
      location_type,
      verification_status,
      incident_phase,
      case_status,
      has_known_suspect,
      suspect_count,
      victim_count,
      witness_count,
      property_loss_flag,
      estimated_damage_value,
      reviewed_by,
      reviewed_at,
      admin_notes
    )
    VALUES
    (
      ?, NULL, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?,
      'VERIFIED',
      'BLOTTERED',
      ?,
      ?, ?, ?, ?, ?, ?,
      ?, UTC_TIMESTAMP(), ?
    )
  ");

  $insert->execute([
    $incidentCode,
    (int)($AUTH_USER["station_id"] ?? 0),
    $blotterEntryNumber,
    $irfEntryNumber,
    $reportSource,
    $reportChannel,
    (int)$crime["id"],
    $crime["crime_name"],
    $crime["crime_category"],
    $crime["focus_crime_code"],
    $crime["ciras_offense_code"],
    $title,
    $narrative,
    $dateIncidentFrom,
    $dateIncidentTo,
    $placeOfIncident,
    $sitio,
    $barangay,
    $cityMunicipality,
    $province,
    $region,
    $lat,
    $lng,
    $locationType,
    $caseStatus,
    $hasKnownSuspect,
    $suspectCount,
    $victimCount,
    $witnessCount,
    $propertyLossFlag,
    $estimatedDamageValue,
    $adminId,
    $notes
  ]);

  $incidentId = (int)$pdo->lastInsertId();

  $personIns = $pdo->prepare("
    INSERT INTO incident_persons
    (
      incident_id,
      person_role,
      family_name,
      first_name,
      middle_name,
      qualifier,
      nickname,
      citizenship,
      sex_gender,
      civil_status,
      birth_date,
      age,
      place_of_birth,
      home_phone,
      mobile_phone,
      email_address,
      current_address,
      current_sitio,
      current_barangay,
      current_city,
      current_province,
      other_address,
      other_sitio,
      other_barangay,
      other_city,
      other_province,
      educational_attainment,
      occupation,
      work_address,
      relation_to_victim,
      is_afp_pnp_personnel,
      rank_title,
      unit_assignment,
      group_affiliation,
      has_previous_criminal_record,
      previous_case_status,
      height_cm,
      weight_kg,
      built,
      eye_color,
      eye_description,
      hair_color,
      hair_description,
      under_influence,
      under_influence_notes,
      guardian_name,
      guardian_address,
      guardian_home_phone,
      guardian_mobile_phone,
      suspect_status
    )
    VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
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
      trim((string)($p["qualifier"] ?? "")) ?: null,
      trim((string)($p["nickname"] ?? "")) ?: null,
      trim((string)($p["citizenship"] ?? "")) ?: null,
      trim((string)($p["sex_gender"] ?? "")) ?: null,
      trim((string)($p["civil_status"] ?? "")) ?: null,
      trim((string)($p["birth_date"] ?? "")) ?: null,
      isset($p["age"]) && $p["age"] !== "" ? (int)$p["age"] : null,
      trim((string)($p["place_of_birth"] ?? "")) ?: null,
      trim((string)($p["home_phone"] ?? "")) ?: null,
      trim((string)($p["mobile_phone"] ?? "")) ?: null,
      trim((string)($p["email_address"] ?? "")) ?: null,
      trim((string)($p["current_address"] ?? "")) ?: null,
      trim((string)($p["current_sitio"] ?? "")) ?: null,
      trim((string)($p["current_barangay"] ?? "")) ?: null,
      trim((string)($p["current_city"] ?? "")) ?: null,
      trim((string)($p["current_province"] ?? "")) ?: null,
      trim((string)($p["other_address"] ?? "")) ?: null,
      trim((string)($p["other_sitio"] ?? "")) ?: null,
      trim((string)($p["other_barangay"] ?? "")) ?: null,
      trim((string)($p["other_city"] ?? "")) ?: null,
      trim((string)($p["other_province"] ?? "")) ?: null,
      trim((string)($p["educational_attainment"] ?? "")) ?: null,
      trim((string)($p["occupation"] ?? "")) ?: null,
      trim((string)($p["work_address"] ?? "")) ?: null,
      trim((string)($p["relation_to_victim"] ?? "")) ?: null,
      !empty($p["is_afp_pnp_personnel"]) ? 1 : 0,
      trim((string)($p["rank_title"] ?? "")) ?: null,
      trim((string)($p["unit_assignment"] ?? "")) ?: null,
      trim((string)($p["group_affiliation"] ?? "")) ?: null,
      !empty($p["has_previous_criminal_record"]) ? 1 : 0,
      trim((string)($p["previous_case_status"] ?? "")) ?: null,
      isset($p["height_cm"]) && $p["height_cm"] !== "" ? (float)$p["height_cm"] : null,
      isset($p["weight_kg"]) && $p["weight_kg"] !== "" ? (float)$p["weight_kg"] : null,
      trim((string)($p["built"] ?? "")) ?: null,
      trim((string)($p["eye_color"] ?? "")) ?: null,
      trim((string)($p["eye_description"] ?? "")) ?: null,
      trim((string)($p["hair_color"] ?? "")) ?: null,
      trim((string)($p["hair_description"] ?? "")) ?: null,
      trim((string)($p["under_influence"] ?? "")) ?: null,
      trim((string)($p["under_influence_notes"] ?? "")) ?: null,
      trim((string)($p["guardian_name"] ?? "")) ?: null,
      trim((string)($p["guardian_address"] ?? "")) ?: null,
      trim((string)($p["guardian_home_phone"] ?? "")) ?: null,
      trim((string)($p["guardian_mobile_phone"] ?? "")) ?: null,
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
    VALUES (?, NULL, 'BLOTTERED', NULL, ?, NULL, 'VERIFIED', ?, ?)
  ");

  $hist->execute([
    $incidentId,
    $caseStatus,
    "Station blotter created via {$reportSource}/{$reportChannel}. " . $notes,
    $adminId
  ]);

  write_audit_log(
  $pdo,
  $AUTH_USER,
  "BLOTTER_CREATED",
  "incident_report",
  $incidentId,
  "Station Admin created a blotter record.",
  [
    "module" => "blotter",
    "incident_id" => $incidentId,
    "new_values" => [
      "incident_code" => $incidentCode,
      "blotter_entry_number" => $blotterEntryNumber,
      "irf_entry_number" => $irfEntryNumber,
      "title" => $title,
      "incident_type" => $crime["crime_name"],
      "crime_category" => $crime["crime_category"],
      "report_source" => $reportSource,
      "report_channel" => $reportChannel,
      "incident_phase" => "BLOTTERED",
      "verification_status" => "VERIFIED",
      "case_status" => $caseStatus,
      "barangay" => $barangay,
      "city_municipality" => $cityMunicipality,
      "province" => $province,
      "lat" => $lat,
      "lng" => $lng,
      "persons_count" => is_array($persons) ? count($persons) : 0,
      "properties_count" => is_array($properties) ? count($properties) : 0,
      "officers_count" => is_array($officers) ? count($officers) : 0,
      "reviewed_by" => $adminId
    ]
  ]
);

  recalc_hotspots_after_incident_save($pdo, $incidentId);
  $alertResult = queue_incident_hotspot_alerts($pdo, $incidentId);

  $newValues = [
    "incident_id" => $incidentId,
    "incident_code" => $incidentCode,
    "blotter_entry_number" => $blotterEntryNumber,
    "irf_entry_number" => $irfEntryNumber,
    "report_source" => $reportSource,
    "report_channel" => $reportChannel,
    "crime_type_id" => (int)$crime["id"],
    "incident_type" => $crime["crime_name"],
    "crime_category" => $crime["crime_category"],
    "title" => $title,
    "narrative" => $narrative,
    "date_incident_from" => $dateIncidentFrom,
    "date_incident_to" => $dateIncidentTo,
    "place_of_incident" => $placeOfIncident,
    "sitio" => $sitio,
    "barangay" => $barangay,
    "city_municipality" => $cityMunicipality,
    "province" => $province,
    "region" => $region,
    "lat" => $lat,
    "lng" => $lng,
    "location_type" => $locationType,
    "verification_status" => "VERIFIED",
    "incident_phase" => "BLOTTERED",
    "case_status" => $caseStatus,
    "has_known_suspect" => $hasKnownSuspect,
    "suspect_count" => $suspectCount,
    "victim_count" => $victimCount,
    "witness_count" => $witnessCount,
    "property_loss_flag" => $propertyLossFlag,
    "estimated_damage_value" => $estimatedDamageValue,
    "reviewed_by" => $adminId,
    "admin_notes" => $notes,
    "persons_count" => count($persons),
    "properties_count" => count($properties),
    "officers_count" => count($officers)
  ];

  write_audit_log(
    $pdo,
    $AUTH_USER,
    "BLOTTER_CREATED",
    "incident_report",
    $incidentId,
    "Station Admin created a blotter record.",
    [
      "module" => "blotter",
      "incident_id" => $incidentId,
      "old_values" => null,
      "new_values" => $newValues
    ]
  );

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Station blotter created successfully",
    "incident_id" => $incidentId,
    "incident_code" => $incidentCode,
    "blotter_entry_number" => $blotterEntryNumber,
    "irf_entry_number" => $irfEntryNumber,
    "alert_created_count" => $alertResult["created"] ?? 0,
    "scope" => [
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}