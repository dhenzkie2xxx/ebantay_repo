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

$allowedTypes = [
  "station_clearance",
  "proof_of_assignment",
  "office_photo",
  "id_card",
  "official_letter",
  "business_permit",
  "other"
];

if (!in_array($documentType, $allowedTypes, true)) {
  auth_out(400, ["ok" => false, "message" => "Invalid document type."]);
}

if (!isset($_FILES["file"])) {
  auth_out(400, ["ok" => false, "message" => "No file uploaded."]);
}

$file = $_FILES["file"];
if ($file["error"] !== UPLOAD_ERR_OK) {
  auth_out(400, ["ok" => false, "message" => "Upload failed."]);
}

$maxBytes = 10 * 1024 * 1024;
if ($file["size"] <= 0 || $file["size"] > $maxBytes) {
  auth_out(400, ["ok" => false, "message" => "Max 10MB allowed."]);
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
$fileExt = $allowedMime[$mime];
$sha256 = hash("sha256", $fileData);

$stationStmt = $pdo->prepare("
  SELECT id, verification_status
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
$status = $station["verification_status"];

if (!in_array($status, station_can_edit_statuses(), true)) {
  auth_out(403, ["ok" => false, "message" => "Cannot upload in current status"]);
}

$isRequired = in_array($documentType, station_required_document_types(), true) ? 1 : 0;

try {
  $pdo->beginTransaction();

  // deactivate old version
  $pdo->prepare("
    UPDATE police_station_documents
    SET is_current = 0
    WHERE station_id = ? AND document_type = ?
  ")->execute([$stationId, $documentType]);

  $stmt = $pdo->prepare("
    INSERT INTO police_station_documents (
      station_id,
      document_type,
      document_label,
      file_name,
      file_ext,
      mime_type,
      file_size,
      file_data,
      sha256,
      remarks,
      is_required,
      is_current,
      uploaded_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
  ");

  $stmt->execute([
    $stationId,
    $documentType,
    $documentLabel,
    $file["name"],
    $fileExt,
    $mime,
    $file["size"],
    $fileData,
    $sha256,
    $remarks,
    $isRequired,
    $AUTH_USER["id"]
  ]);

  $pdo->commit();

  auth_out(200, [
    "ok" => true,
    "message" => "Uploaded successfully"
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  auth_out(500, ["ok" => false, "message" => "Server error"]);
}