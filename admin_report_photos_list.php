<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$incidentId = (int)($_GET["report_id"] ?? 0);
if ($incidentId <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false, "message"=>"Missing report_id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, thumb, thumb_mime_type, mime_type, created_at
  FROM incident_report_photos
  WHERE incident_id = ?
  ORDER BY id ASC
");
$stmt->execute([$incidentId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$photos = array_map(function($r){
  $mime = $r["thumb_mime_type"] ?: $r["mime_type"] ?: "image/jpeg";
  return [
    "id" => (int)$r["id"],
    "mime_type" => $mime,
    "created_at" => $r["created_at"],
    "thumb_b64" => $r["thumb"] ? base64_encode($r["thumb"]) : null
  ];
}, $rows);

echo json_encode(["ok"=>true, "photos"=>$photos]);