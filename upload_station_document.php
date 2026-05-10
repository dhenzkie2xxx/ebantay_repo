<?php
require_once __DIR__ . "/require_admin_account.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$documentType = station_clean($_POST["document_type"] ?? "");
$documentLabel = station_nullable_string($_POST["document_label"] ?? null);
$remarks = station_nullable_string($_POST["remarks"] ?? null);

$allowedTypes = station_all_document_types($pdo);

if (!in_array($documentType, $allowedTypes, true)) {
  auth_out(400, ["ok" => false, "message" => "Invalid document type."]);
}

if (!isset($_FILES["file"])) {
  auth_out(400, ["ok" => false, "message" => "No file uploaded."]);
}

$file = $_FILES["file"];

if (!isset($file["error"]) || $file["error"] !== UPLOAD_ERR_OK) {
  auth_out(400, ["ok" => false, "message" => "Upload failed."]);
}

$maxBytes = 10 * 1024 * 1024; // 10MB
if (!isset($file["size"]) || $file["size"] <= 0 || $file["size"] > $maxBytes) {
  auth_out(400, ["ok" => false, "message" => "Max 10MB allowed."]);
}

if (!is_uploaded_file($file["tmp_name"])) {
  auth_out(400, ["ok" => false, "message" => "Invalid uploaded file."]);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file["tmp_name"]);

$allowedMime = [
  "application/pdf" => "pdf",
  "image/jpeg" => "jpg",
  "image/png" => "png"
];

if (!isset($allowedMime[$mime])) {
  auth_out(400, ["ok" => false, "message" => "Only PDF/JPG/PNG allowed."]);
}

$fileData = file_get_contents($file["tmp_name"]);
if ($fileData === false) {
  auth_out(400, ["ok" => false, "message" => "Failed to read uploaded file."]);
}

$fileExt = $allowedMime[$mime];
$sha256 = hash("sha256", $fileData);

try {
  $stationStmt = $pdo->prepare("
    SELECT
      id,
      verification_status
    FROM police_stations
    WHERE created_by = ?
    LIMIT 1
  ");
  $stationStmt->execute([$AUTH_USER["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    auth_out(400, ["ok" => false, "message" => "Register station first."]);
  }

  $stationId = (int)$station["id"];
  $status = (string)($station["verification_status"] ?? "");

  if (!in_array($status, station_can_edit_statuses(), true)) {
    auth_out(403, ["ok" => false, "message" => "Cannot upload in current status."]);
  }

  $isRequired = in_array($documentType, station_required_document_types($pdo), true) ? 1 : 0;

  $stmt = $pdo->prepare("
    INSERT INTO police_station_documents (
      station_id,
      document_type,
      document_label,
      file_name,
      mime_type,
      file_size,
      sha256,
      remarks,
      is_required,
      is_current,
      uploaded_by,
      uploaded_at,
      file_data,
      file_ext
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?, ?)
  ");

  $stmt->execute([
    $stationId,
    $documentType,
    $documentLabel,
    $file["name"],
    $mime,
    (int)$file["size"],
    $sha256,
    $remarks,
    $isRequired,
    $AUTH_USER["id"],
    $fileData,
    $fileExt
  ]);

  auth_out(200, [
    "ok" => true,
    "message" => "Uploaded successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error.",
    "error" => $e->getMessage()
  ]);
}