<?php
require_once __DIR__ . "/require_admin.php";

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false, "message"=>"Missing id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, mime_type, image
  FROM incident_report_photos
  WHERE id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
  http_response_code(404);
  echo json_encode(["ok"=>false, "message"=>"Not found"]);
  exit;
}

echo json_encode([
  "ok" => true,
  "photo" => [
    "id" => (int)$r["id"],
    "mime_type" => $r["mime_type"] ?: "image/jpeg",
    "image_b64" => base64_encode($r["image"])
  ]
]);