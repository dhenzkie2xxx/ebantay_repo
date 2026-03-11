<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Missing id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    r.*,
    u.firstname,
    u.lastname,
    u.email,
    u.username
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  WHERE r.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
  http_response_code(404);
  echo json_encode(["ok"=>false,"message"=>"Not found"]);
  exit;
}

$pc = $pdo->prepare("SELECT COUNT(*) c FROM incident_report_photos WHERE incident_id = ?");
$pc->execute([$id]);
$photosCount = (int)($pc->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);

echo json_encode([
  "ok" => true,
  "report" => [
    "id" => (int)$r["id"],
    "incident_code" => $r["incident_code"],
    "irf_entry_number" => $r["irf_entry_number"],
    "blotter_entry_number" => $r["blotter_entry_number"],
    "report_source" => $r["report_source"],
    "report_channel" => $r["report_channel"],
    "crime_type_id" => $r["crime_type_id"] !== null ? (int)$r["crime_type_id"] : null,
    "title" => $r["title"],
    "category" => $r["incident_type"],
    "crime_category" => $r["crime_category"],
    "focus_crime_code" => $r["focus_crime_code"],
    "ciras_offense_code" => $r["ciras_offense_code"],
    "description" => $r["narrative"],
    "date_reported" => $r["date_reported"],
    "date_incident_from" => $r["date_incident_from"],
    "date_incident_to" => $r["date_incident_to"],
    "place_of_incident" => $r["place_of_incident"],
    "sitio" => $r["sitio"],
    "barangay" => $r["barangay"],
    "city_municipality" => $r["city_municipality"],
    "province" => $r["province"],
    "region" => $r["region"],
    "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
    "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
    "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
    "location_type" => $r["location_type"],
    "is_hotspot_related" => (int)$r["is_hotspot_related"],
    "hotspot_id" => $r["hotspot_id"] !== null ? (int)$r["hotspot_id"] : null,
    "risk_status" => $r["risk_status"],
    "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
    "risk_radius_m" => $r["risk_radius_m"] !== null ? (int)$r["risk_radius_m"] : null,
    "verification_status" => $r["verification_status"],
    "incident_phase" => $r["incident_phase"],
    "case_status" => $r["case_status"],
    "has_known_suspect" => (int)$r["has_known_suspect"],
    "suspect_count" => (int)$r["suspect_count"],
    "victim_count" => (int)$r["victim_count"],
    "witness_count" => (int)$r["witness_count"],
    "property_loss_flag" => (int)$r["property_loss_flag"],
    "estimated_damage_value" => $r["estimated_damage_value"] !== null ? (float)$r["estimated_damage_value"] : null,
    "device_time" => $r["device_time"],
    "created_at" => $r["created_at"],
    "updated_at" => $r["updated_at"],
    "admin_notes" => $r["admin_notes"],
    "reviewed_by" => $r["reviewed_by"] !== null ? (int)$r["reviewed_by"] : null,
    "reviewed_at" => $r["reviewed_at"],
    "resolved_at" => $r["resolved_at"],
    "photos_count" => $photosCount,
    "reporter" => [
      "firstname" => $r["firstname"],
      "lastname" => $r["lastname"],
      "email" => $r["email"],
      "username" => $r["username"]
    ]
  ]
]);