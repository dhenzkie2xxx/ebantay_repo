<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  out(400, ["ok" => false, "message" => "Missing id"]);
}

$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;

if ($role === "admin" && $stationId <= 0) {
  out(403, [
    "ok" => false,
    "message" => "Admin station is not configured."
  ]);
}

$sql = "
  SELECT
    r.*,
    ps.station_name AS assigned_station_name,
    ps.station_code AS assigned_station_code,
    u.firstname,
    u.lastname,
    u.email,
    u.username
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  LEFT JOIN police_stations ps ON ps.id = r.assigned_station_id
  WHERE r.id = :id
";

$params = [
  ":id" => $id
];

if ($role === "admin") {
  $sql .= " AND r.assigned_station_id = :station_id ";
  $params[":station_id"] = $stationId;
}

$sql .= " LIMIT 1 ";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  if ($k === ":id" || $k === ":station_id") {
    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $stmt->bindValue($k, $v);
  }
}
$stmt->execute();
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
  out(404, ["ok" => false, "message" => "Not found"]);
}

$pc = $pdo->prepare("SELECT COUNT(*) c FROM incident_report_photos WHERE incident_id = ?");
$pc->execute([$id]);
$photosCount = (int)($pc->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_id" => $role === "admin" ? $stationId : null,
    "is_global" => $role === "super_admin"
  ],
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
    "assigned_station_id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
    "assigned_station_name" => $r["assigned_station_name"],
    "assigned_station_code" => $r["assigned_station_code"],
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