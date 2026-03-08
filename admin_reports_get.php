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
    "title" => $r["title"],
    "category" => $r["incident_type"],
    "crime_category" => $r["crime_category"],
    "description" => $r["narrative"],
    "risk_status" => $r["risk_status"],
    "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
    "risk_radius_m" => $r["risk_radius_m"] !== null ? (int)$r["risk_radius_m"] : null,
    "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
    "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
    "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
    "device_time" => $r["device_time"],
    "created_at" => $r["created_at"],
    "date_reported" => $r["date_reported"],
    "date_incident_from" => $r["date_incident_from"],
    "barangay" => $r["barangay"],
    "city_municipality" => $r["city_municipality"],
    "province" => $r["province"],
    "verification_status" => $r["verification_status"],
    "incident_phase" => $r["incident_phase"],
    "case_status" => $r["case_status"],
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