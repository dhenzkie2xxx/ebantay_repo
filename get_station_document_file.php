<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";

$documentId = (int)($_GET["id"] ?? 0);
if ($documentId <= 0) {
  http_response_code(400);
  echo "Invalid document ID";
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      d.id,
      d.station_id,
      d.file_data,
      d.mime_type,
      d.file_name,
      ps.created_by
    FROM police_station_documents d
    JOIN police_stations ps ON ps.id = d.station_id
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->execute([$documentId]);
  $file = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$file) {
    http_response_code(404);
    echo "File not found";
    exit;
  }

  $isSuper = (($AUTH_USER["role"] ?? "") === "super_admin");
  $isOwnerAdmin = (($AUTH_USER["role"] ?? "") === "admin" && (int)$file["created_by"] === (int)$AUTH_USER["id"]);

  if (!$isSuper && !$isOwnerAdmin) {
    http_response_code(403);
    echo "Access denied";
    exit;
  }

  header("Content-Type: " . $file["mime_type"]);
  header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$file["file_name"]) . '"');
  echo $file["file_data"];
} catch (Throwable $e) {
  http_response_code(500);
  echo "Server error";
}