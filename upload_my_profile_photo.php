<?php
require_once __DIR__ . "/require_account_user.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

if (!isset($_FILES["file"])) {
  auth_out(400, ["ok" => false, "message" => "No file uploaded."]);
}

$file = $_FILES["file"];

if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  auth_out(400, ["ok" => false, "message" => "File upload failed."]);
}

$maxBytes = 5 * 1024 * 1024;
if (($file["size"] ?? 0) <= 0 || ($file["size"] ?? 0) > $maxBytes) {
  auth_out(400, ["ok" => false, "message" => "Profile photo must be between 1 byte and 5 MB."]);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file["tmp_name"]);

$allowedMime = [
  "image/jpeg",
  "image/png",
  "image/webp"
];

if (!in_array($mime, $allowedMime, true)) {
  auth_out(400, ["ok" => false, "message" => "Only JPG, PNG, and WEBP images are allowed."]);
}

$fileData = file_get_contents($file["tmp_name"]);
if ($fileData === false) {
  auth_out(500, ["ok" => false, "message" => "Failed to read uploaded file."]);
}

try {
  $stmt = $pdo->prepare("
    UPDATE users
    SET
      profile_photo = ?,
      profile_photo_mime = ?,
      profile_photo_name = ?
    WHERE id = ?
  ");
  $stmt->bindParam(1, $fileData, PDO::PARAM_LOB);
  $stmt->bindValue(2, $mime, PDO::PARAM_STR);
  $stmt->bindValue(3, $file["name"], PDO::PARAM_STR);
  $stmt->bindValue(4, $AUTH_USER["id"], PDO::PARAM_INT);
  $stmt->execute();

  auth_out(200, [
    "ok" => true,
    "message" => "Profile photo uploaded successfully.",
    "profile_photo_url" => "/api/get_my_profile_photo.php?token=" . urlencode(bearer_token())
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}