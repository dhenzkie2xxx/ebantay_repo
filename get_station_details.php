<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$stationId = (int)($_GET["station_id"] ?? 0);
if ($stationId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid station ID."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT
      ps.*,
      u.id AS admin_user_id,
      u.firstname,
      u.lastname,
      u.username,
      u.email,
      u.account_status,
      u.valid,
      u.is_email_verified
    FROM police_stations ps
    JOIN users u ON u.id = ps.created_by
    WHERE ps.id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $docStmt = $pdo->prepare("
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
  $docStmt->execute([$stationId]);

  $documents = [];
  while ($row = $docStmt->fetch(PDO::FETCH_ASSOC)) {
    $documents[] = [
      "id" => (int)$row["id"],
      "document_type" => $row["document_type"],
      "document_label" => $row["document_label"],
      "file_name" => $row["file_name"],
      "file_ext" => $row["file_ext"],
      "mime_type" => $row["mime_type"],
      "file_size" => (int)$row["file_size"],
      "remarks" => $row["remarks"],
      "is_required" => (int)$row["is_required"],
      "is_current" => (int)$row["is_current"],
      "uploaded_at" => $row["uploaded_at"],
      "download_url" => "/api/get_station_document_file.php?id=" . $row["id"]
    ];
  }

  $histStmt = $pdo->prepare("
    SELECT
      h.id,
      h.old_status,
      h.new_status,
      h.remarks,
      h.acted_at,
      u.firstname,
      u.lastname,
      u.username
    FROM police_station_verification_history h
    LEFT JOIN users u ON u.id = h.acted_by
    WHERE h.station_id = ?
    ORDER BY h.acted_at DESC, h.id DESC
  ");
  $histStmt->execute([$stationId]);

  $history = [];
  while ($row = $histStmt->fetch(PDO::FETCH_ASSOC)) {
    $history[] = [
      "id" => (int)$row["id"],
      "old_status" => $row["old_status"],
      "new_status" => $row["new_status"],
      "remarks" => $row["remarks"],
      "acted_at" => $row["acted_at"],
      "acted_by" => trim(($row["firstname"] ?? "") . " " . ($row["lastname"] ?? "")),
      "acted_by_username" => $row["username"]
    ];
  }

  auth_out(200, [
    "ok" => true,
    "station" => [
      "id" => (int)$station["id"],
      "station_code" => $station["station_code"],
      "station_name" => $station["station_name"],
      "station_type" => $station["station_type"],
      "region" => $station["region"],
      "province" => $station["province"],
      "city_municipality" => $station["city_municipality"],
      "barangay" => $station["barangay"],
      "sitio" => $station["sitio"],
      "street_address" => $station["street_address"],
      "full_address" => $station["full_address"],
      "lat" => (float)$station["lat"],
      "lng" => (float)$station["lng"],
      "accuracy_m" => $station["accuracy_m"] !== null ? (int)$station["accuracy_m"] : null,
      "contact_person" => $station["contact_person"],
      "contact_position" => $station["contact_position"],
      "contact_mobile" => $station["contact_mobile"],
      "contact_landline" => $station["contact_landline"],
      "contact_email" => $station["contact_email"],
      "operating_hours" => $station["operating_hours"],
      "emergency_contact" => $station["emergency_contact"],
      "verification_status" => $station["verification_status"],
      "is_active" => (int)$station["is_active"],
      "submitted_at" => $station["submitted_at"],
      "reviewed_at" => $station["reviewed_at"],
      "approved_at" => $station["approved_at"],
      "rejection_reason" => $station["rejection_reason"],
      "created_at" => $station["created_at"],
      "updated_at" => $station["updated_at"]
    ],
    "admin" => [
      "id" => (int)$station["admin_user_id"],
      "firstname" => $station["firstname"],
      "lastname" => $station["lastname"],
      "username" => $station["username"],
      "email" => $station["email"],
      "account_status" => $station["account_status"],
      "valid" => $station["valid"],
      "is_email_verified" => (int)$station["is_email_verified"]
    ],
    "documents" => $documents,
    "history" => $history
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}