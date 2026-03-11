<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    id,
    incident_code,
    crime_type_id,
    title,
    incident_type,
    crime_category,
    narrative,
    verification_status,
    incident_phase,
    case_status,
    report_source,
    report_channel,
    blotter_entry_number,
    irf_entry_number,
    has_known_suspect,
    suspect_count,
    victim_count,
    witness_count,
    property_loss_flag,
    estimated_damage_value,
    date_incident_from,
    date_incident_to,
    place_of_incident,
    sitio,
    barangay,
    city_municipality,
    province,
    region,
    location_type,
    lat,
    lng,
    accuracy_m,
    admin_notes,
    date_reported,
    created_at
  FROM incident_reports
  WHERE id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  echo json_encode(["ok" => false, "message" => "Incident not found"]);
  exit;
}

$personsStmt = $pdo->prepare("
  SELECT *
  FROM incident_persons
  WHERE incident_id = ?
  ORDER BY id ASC
");
$personsStmt->execute([$id]);
$persons = $personsStmt->fetchAll(PDO::FETCH_ASSOC);

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
  "incident" => [
    "id" => (int)$row["id"],
    "incident_code" => $row["incident_code"],
    "crime_type_id" => $row["crime_type_id"] !== null ? (int)$row["crime_type_id"] : "",
    "title" => $row["title"],
    "incident_type" => $row["incident_type"],
    "crime_category" => $row["crime_category"],
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
      "suspect_status" => $r["suspect_status"]
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