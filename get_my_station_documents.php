<?php
require_once __DIR__ . "/require_admin_account.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stationStmt = $pdo->prepare("
    SELECT id
    FROM police_stations
    WHERE created_by = ?
    LIMIT 1
  ");
  $stationStmt->execute([$AUTH_USER["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(200, [
      "ok" => true,
      "items" => []
    ]);
  }

  $stationId = (int)$station["id"];

  $stmt = $pdo->prepare("
    SELECT
      id,
      document_type,
      document_label,
      file_name,
      file_ext,
      mime_type,
      file_size,
      remarks,
      is_required,
      is_current,
      uploaded_at
    FROM police_station_documents
    WHERE station_id = ?
      AND is_current = 1
    ORDER BY uploaded_at DESC, id DESC
  ");
  $stmt->execute([$stationId]);

  $items = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$row["id"],
      "document_type" => $row["document_type"],
      "document_label" => $row["document_label"],
      "file_name" => $row["file_name"],
      "file_ext" => $row["file_ext"],
      "mime_type" => $row["mime_type"],
      "file_size" => (int)$row["file_size"],
      "download_url" => "/api/get_station_document_file.php?id=" . $row["id"],
      "remarks" => $row["remarks"],
      "is_required" => (int)$row["is_required"],
      "is_current" => (int)$row["is_current"],
      "uploaded_at" => $row["uploaded_at"]
    ];
  }

  auth_out(200, [
    "ok" => true,
    "items" => $items
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}