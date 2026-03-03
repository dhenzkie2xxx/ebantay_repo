<?php
require_once __DIR__ . "/require_admin.php";

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Missing id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    r.*,
    u.firstname, u.lastname, u.email
  FROM incident_reports r
  JOIN users u ON u.id = r.user_id
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

$pc = $pdo->prepare("SELECT COUNT(*) c FROM incident_report_photos WHERE report_id = ?");
$pc->execute([$id]);
$photosCount = (int)$pc->fetch(PDO::FETCH_ASSOC)["c"];

echo json_encode([
  "ok" => true,
  "report" => [
    "id" => (int)$r["id"],
    "title" => $r["title"],
    "category" => $r["category"],
    "description" => $r["description"],
    "risk_status" => $r["risk_status"],
    "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
    "risk_radius_m" => (int)$r["risk_radius_m"],
    "lat" => (float)$r["lat"],
    "lng" => (float)$r["lng"],
    "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
    "device_time" => $r["device_time"],
    "created_at" => $r["created_at"],
    "status" => $r["status"],
    "admin_notes" => $r["admin_notes"],
    "reviewed_by" => $r["reviewed_by"] !== null ? (int)$r["reviewed_by"] : null,
    "reviewed_at" => $r["reviewed_at"],
    "resolved_at" => $r["resolved_at"],
    "photos_count" => $photosCount,
    "reporter" => [
      "firstname" => $r["firstname"],
      "lastname" => $r["lastname"],
      "email" => $r["email"]
    ]
  ]
]);