<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/location_resolver.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function norm_text($v): ?string {
  $v = trim((string)($v ?? ""));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v === "" ? null : $v;
}

$id = (int)($_GET["id"] ?? 0);

if ($id <= 0) {
  out(400, ["ok" => false, "message" => "Missing id"]);
}

$role = (string)($AUTH_USER["role"] ?? "");
$isSuperAdmin = $role === "super_admin";

$stationProvince = norm_text($AUTH_USER["station_province"] ?? null);
$stationCity = norm_text($AUTH_USER["station_city_municipality"] ?? null);
$stationRegion = norm_text($AUTH_USER["station_region"] ?? null);

if (!$isSuperAdmin) {
  $canon = canonicalize_scope($pdo, $stationRegion, $stationProvince, $stationCity);

  if (empty($canon["ok"])) {
    out(403, [
      "ok" => false,
      "message" => "Unable to resolve your station city/municipality scope."
    ]);
  }

  $stationProvince = $canon["province"];
  $stationCity = $canon["city_municipality"];
}

$whereScope = "";
$params = [$id];

if (!$isSuperAdmin) {
  $whereScope = "
    AND LOWER(TRIM(ir.province)) = LOWER(TRIM(?))
    AND LOWER(TRIM(ir.city_municipality)) = LOWER(TRIM(?))
  ";
  $params[] = $stationProvince;
  $params[] = $stationCity;
}

$stmt = $pdo->prepare("
  SELECT
    ir.id,
    ir.incident_code,
    ir.crime_type_id,
    ir.title,
    COALESCE(NULLIF(TRIM(ir.incident_type), ''), ct.crime_name, 'Other Incident') AS incident_type,
    COALESCE(NULLIF(TRIM(ir.crime_category), ''), ct.crime_category, 'OTHER') AS crime_category,
    ir.narrative,
    ir.verification_status,
    ir.incident_phase,
    ir.case_status,
    ir.report_source,
    ir.report_channel,
    ir.blotter_entry_number,
    ir.irf_entry_number,
    ir.has_known_suspect,
    ir.suspect_count,
    ir.victim_count,
    ir.witness_count,
    ir.property_loss_flag,
    ir.estimated_damage_value,
    ir.date_incident_from,
    ir.date_incident_to,
    ir.place_of_incident,
    ir.sitio,
    ir.barangay,
    ir.city_municipality,
    ir.province,
    ir.region,
    ir.location_type,
    ir.lat,
    ir.lng,
    ir.accuracy_m,
    ir.admin_notes,
    ir.date_reported,
    ir.created_at
  FROM incident_reports ir
  LEFT JOIN crime_types ct ON ct.id = ir.crime_type_id
  WHERE ir.id = ?
  $whereScope
  LIMIT 1
");

$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  out(404, [
    "ok" => false,
    "message" => "Incident not found or outside your station city/municipality."
  ]);
}

$personsStmt = $pdo->prepare("
  SELECT *
  FROM incident_persons
  WHERE incident_id = ?
  ORDER BY id ASC
");
$personsStmt->execute([$id]);
$persons = $personsStmt->fetchAll(PDO::FETCH_ASSOC);

$hasReportingPerson = false;

foreach ($persons as $p) {
  if (strtoupper((string)$p["person_role"]) === "REPORTING_PERSON") {
    $hasReportingPerson = true;
    break;
  }
}

if (!$hasReportingPerson && !empty($row["reporter_user_id"])) {
  $userStmt = $pdo->prepare("
    SELECT
      u.firstname,
      u.lastname,
      u.email,
      up.mobile_number,
      up.address_text,
      up.barangay,
      up.city_municipality,
      up.province
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
  ");

  $userStmt->execute([(int)$row["reporter_user_id"]]);
  $u = $userStmt->fetch(PDO::FETCH_ASSOC);

  if ($u) {
    array_unshift($persons, [
      "id" => 0,
      "incident_id" => $row["id"],
      "person_role" => "REPORTING_PERSON",
      "linked_user_id" => $row["reporter_user_id"],

      "family_name" => $u["lastname"] ?? "",
      "first_name" => $u["firstname"] ?? "",
      "middle_name" => "",
      "qualifier" => "",
      "nickname" => "",

      "citizenship" => "",
      "sex_gender" => "",
      "civil_status" => "",
      "birth_date" => null,
      "age" => null,
      "place_of_birth" => "",

      "home_phone" => "",
      "mobile_phone" => $u["mobile_number"] ?? "",
      "email_address" => $u["email"] ?? "",

      "current_address" => $u["address_text"] ?? "",
      "current_sitio" => "",
      "current_barangay" => $u["barangay"] ?? "",
      "current_city" => $u["city_municipality"] ?? "",
      "current_province" => $u["province"] ?? "",

      "other_address" => "",
      "other_sitio" => "",
      "other_barangay" => "",
      "other_city" => "",
      "other_province" => "",

      "educational_attainment" => "",
      "occupation" => "",
      "work_address" => "",
      "relation_to_victim" => "",

      "is_afp_pnp_personnel" => 0,
      "rank_title" => "",
      "unit_assignment" => "",
      "group_affiliation" => "",

      "has_previous_criminal_record" => 0,
      "previous_case_status" => "",

      "height_cm" => null,
      "weight_kg" => null,
      "built" => "",
      "eye_color" => "",
      "eye_description" => "",
      "hair_color" => "",
      "hair_description" => "",

      "under_influence" => "",
      "under_influence_notes" => "",

      "guardian_name" => "",
      "guardian_address" => "",
      "guardian_home_phone" => "",
      "guardian_mobile_phone" => "",

      "suspect_status" => "UNKNOWN",
      "created_at" => null
    ]);
  }
}

$propertiesStmt = $pdo->prepare("
  SELECT *
  FROM incident_properties
  WHERE incident_id = ?
  ORDER BY id ASC
");
$propertiesStmt->execute([$id]);
$properties = $propertiesStmt->fetchAll(PDO::FETCH_ASSOC);

$officersStmt = $pdo->prepare("
  SELECT *
  FROM incident_officers
  WHERE incident_id = ?
  ORDER BY id ASC
");
$officersStmt->execute([$id]);
$officers = $officersStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "scope" => [
    "is_super_admin" => $isSuperAdmin,
    "province" => $isSuperAdmin ? null : $stationProvince,
    "city_municipality" => $isSuperAdmin ? null : $stationCity
  ],
  "incident" => [
    "id" => (int)$row["id"],
    "incident_code" => $row["incident_code"],
    "crime_type_id" => $row["crime_type_id"] !== null ? (int)$row["crime_type_id"] : "",
    "title" => $row["title"],
    "incident_type" => $row["incident_type"] ?: "Other Incident",
    "crime_category" => $row["crime_category"] ?: "OTHER",
    "narrative" => $row["narrative"],
    "verification_status" => $row["verification_status"],
    "incident_phase" => $row["incident_phase"],
    "case_status" => $row["case_status"],
    "report_source" => $row["report_source"],
    "report_channel" => $row["report_channel"],
    "blotter_entry_number" => $row["blotter_entry_number"],
    "irf_entry_number" => $row["irf_entry_number"],
    "has_known_suspect" => (int)$row["has_known_suspect"],
    "suspect_count" => (int)$row["suspect_count"],
    "victim_count" => (int)$row["victim_count"],
    "witness_count" => (int)$row["witness_count"],
    "property_loss_flag" => (int)$row["property_loss_flag"],
    "estimated_damage_value" => $row["estimated_damage_value"] !== null ? (float)$row["estimated_damage_value"] : "",
    "date_incident_from" => $row["date_incident_from"],
    "date_incident_to" => $row["date_incident_to"],
    "place_of_incident" => $row["place_of_incident"],
    "sitio" => $row["sitio"],
    "barangay" => $row["barangay"],
    "city_municipality" => $row["city_municipality"],
    "province" => $row["province"],
    "region" => $row["region"],
    "location_type" => $row["location_type"],
    "lat" => $row["lat"] !== null ? (float)$row["lat"] : "",
    "lng" => $row["lng"] !== null ? (float)$row["lng"] : "",
    "accuracy_m" => $row["accuracy_m"] !== null ? (int)$row["accuracy_m"] : "",
    "admin_notes" => $row["admin_notes"],
    "date_reported" => $row["date_reported"],
    "created_at" => $row["created_at"]
  ],
  "persons" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "person_role" => $r["person_role"],
      "family_name" => $r["family_name"],
      "first_name" => $r["first_name"],
      "middle_name" => $r["middle_name"],
      "nickname" => $r["nickname"],
      "sex_gender" => $r["sex_gender"],
      "civil_status" => $r["civil_status"],
      "birth_date" => $r["birth_date"],
      "age" => $r["age"] !== null ? (int)$r["age"] : "",
      "mobile_phone" => $r["mobile_phone"],
      "email_address" => $r["email_address"],
      "current_address" => $r["current_address"],
      "current_sitio" => $r["current_sitio"],
      "current_barangay" => $r["current_barangay"],
      "current_city" => $r["current_city"],
      "current_province" => $r["current_province"],
      "occupation" => $r["occupation"],
      "relation_to_victim" => $r["relation_to_victim"],
      "suspect_status" => $r["suspect_status"],
      "qualifier" => $r["qualifier"],
      "citizenship" => $r["citizenship"],
      "place_of_birth" => $r["place_of_birth"],
      "home_phone" => $r["home_phone"],
      "other_address" => $r["other_address"],
      "other_sitio" => $r["other_sitio"],
      "other_barangay" => $r["other_barangay"],
      "other_city" => $r["other_city"],
      "other_province" => $r["other_province"],
      "educational_attainment" => $r["educational_attainment"],
      "work_address" => $r["work_address"],
      "is_afp_pnp_personnel" => (int)$r["is_afp_pnp_personnel"],
      "rank_title" => $r["rank_title"],
      "unit_assignment" => $r["unit_assignment"],
      "group_affiliation" => $r["group_affiliation"],
      "has_previous_criminal_record" => (int)$r["has_previous_criminal_record"],
      "previous_case_status" => $r["previous_case_status"],
      "height_cm" => $r["height_cm"] !== null ? (float)$r["height_cm"] : "",
      "weight_kg" => $r["weight_kg"] !== null ? (float)$r["weight_kg"] : "",
      "built" => $r["built"],
      "eye_color" => $r["eye_color"],
      "eye_description" => $r["eye_description"],
      "hair_color" => $r["hair_color"],
      "hair_description" => $r["hair_description"],
      "under_influence" => $r["under_influence"],
      "under_influence_notes" => $r["under_influence_notes"],
      "guardian_name" => $r["guardian_name"],
      "guardian_address" => $r["guardian_address"],
      "guardian_home_phone" => $r["guardian_home_phone"],
      "guardian_mobile_phone" => $r["guardian_mobile_phone"],
    ];
  }, $persons),
  "properties" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "property_role" => $r["property_role"],
      "property_type" => $r["property_type"],
      "description" => $r["description"],
      "quantity" => (int)$r["quantity"],
      "estimated_value" => $r["estimated_value"] !== null ? (float)$r["estimated_value"] : "",
      "recovered_flag" => (int)$r["recovered_flag"],
      "serial_number" => $r["serial_number"],
      "plate_number" => $r["plate_number"]
    ];
  }, $properties),
  "officers" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "officer_role" => $r["officer_role"],
      "rank_title" => $r["rank_title"],
      "full_name" => $r["full_name"],
      "designation" => $r["designation"],
      "police_station" => $r["police_station"],
      "mobile_phone" => $r["mobile_phone"]
    ];
  }, $officers)
]);