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
    title,
    incident_type,
    crime_category,
    narrative,
    verification_status,
    incident_phase,
    case_status,
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

echo json_encode([
  "ok" => true,
  "incident" => [
    "id" => (int)$row["id"],
    "incident_code" => $row["incident_code"],
    "title" => $row["title"],
    "incident_type" => $row["incident_type"],
    "crime_category" => $row["crime_category"],
    "narrative" => $row["narrative"],
    "verification_status" => $row["verification_status"],
    "incident_phase" => $row["incident_phase"],
    "case_status" => $row["case_status"],
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
    "admin_notes" => $row["admin_notes"],
    "date_reported" => $row["date_reported"],
    "created_at" => $row["created_at"]
  ]
]);