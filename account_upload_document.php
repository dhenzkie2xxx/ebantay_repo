<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function get_bearer_or_post_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $postToken = trim((string)($_POST["token"] ?? ""));
  if ($postToken !== "") return $postToken;

  return "";
}

function get_user_profile(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("
    SELECT
      user_id,
      barangay,
      city_municipality,
      province,
      region
    FROM user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}

function requirement_applies_to_user(PDO $pdo, array $requirement, array $profile): bool {
  $reqStationId = $requirement["station_id"] !== null ? (int)$requirement["station_id"] : null;
  $reqProvince = normalize_scope_value($requirement["province"] ?? null);
  $reqCity = normalize_scope_value($requirement["city_municipality"] ?? null);

  $userProvince = normalize_scope_value($profile["province"] ?? null);
  $userCity = normalize_scope_value($profile["city_municipality"] ?? null);

  // Global requirement
  if ($reqStationId === null && $reqProvince === null && $reqCity === null) {
    return true;
  }

  // Province + city scoped requirement
  if ($reqStationId === null) {
    if (!$userProvince || !$userCity || !$reqProvince || !$reqCity) return false;

    return
      strcasecmp($userProvince, $reqProvince) === 0 &&
      strcasecmp($userCity, $reqCity) === 0;
  }

  // Station-scoped requirement -> compare through station location
  $stmt = $pdo->prepare("
    SELECT
      province,
      city_municipality
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$reqStationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) return false;

  $stationProvince = normalize_scope_value($station["province"] ?? null);
  $stationCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$userProvince || !$userCity || !$stationProvince || !$stationCity) return false;

  return
    strcasecmp($userProvince, $stationProvince) === 0 &&
    strcasecmp($userCity, $stationCity) === 0;
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_post_token();
  if ($token === "") {
    out(401, ["ok" => false, "message" => "Missing token"]);
  }

  $user = auth_get_user_by_token($pdo, $token);
  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if (strtolower((string)($user["role"] ?? "")) !== "citizen") {
    out(403, ["ok" => false, "message" => "Only citizen users can upload account documents"]);
  }

  $userId = (int)$user["id"];
  $requirementId = (int)($_POST["requirement_id"] ?? 0);

  if ($requirementId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid requirement_id"]);
  }

  if (!isset($_FILES["document"])) {
    out(400, ["ok" => false, "message" => "No document file uploaded"]);
  }

  $file = $_FILES["document"];

  if (!isset($file["error"]) || is_array($file["error"])) {
    out(400, ["ok" => false, "message" => "Invalid upload parameters"]);
  }

  switch ((int)$file["error"]) {
    case UPLOAD_ERR_OK:
      break;
    case UPLOAD_ERR_NO_FILE:
      out(400, ["ok" => false, "message" => "No file sent"]);
      break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
      out(400, ["ok" => false, "message" => "Uploaded file is too large"]);
      break;
    default:
      out(400, ["ok" => false, "message" => "Unknown upload error"]);
  }

  $tmpPath = $file["tmp_name"] ?? "";
  if ($tmpPath === "" || !is_uploaded_file($tmpPath)) {
    out(400, ["ok" => false, "message" => "Invalid uploaded file"]);
  }

  $fileSize = isset($file["size"]) ? (int)$file["size"] : 0;
  if ($fileSize <= 0) {
    out(400, ["ok" => false, "message" => "Uploaded file is empty"]);
  }

  // Optional size cap: 8MB
  $maxBytes = 8 * 1024 * 1024;
  if ($fileSize > $maxBytes) {
    out(400, ["ok" => false, "message" => "File exceeds 8MB upload limit"]);
  }

  $fileName = trim((string)($file["name"] ?? "document.bin"));
  $mimeType = trim((string)($file["type"] ?? ""));

  // Safer MIME detection
  if (function_exists("finfo_open")) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $detected = finfo_file($finfo, $tmpPath);
      if (is_string($detected) && $detected !== "") {
        $mimeType = $detected;
      }
      finfo_close($finfo);
    }
  }

  $allowedMime = [
    "image/jpeg",
    "image/png",
    "application/pdf",
    "image/webp",
  ];

  if (!in_array($mimeType, $allowedMime, true)) {
    out(400, [
      "ok" => false,
      "message" => "Unsupported file type. Allowed: JPG, PNG, WEBP, PDF"
    ]);
  }

  $blob = file_get_contents($tmpPath);
  if ($blob === false) {
    out(500, ["ok" => false, "message" => "Failed to read uploaded file"]);
  }

  $profile = get_user_profile($pdo, $userId);
  if (!$profile) {
    out(422, [
      "ok" => false,
      "message" => "Please complete your profile location details before uploading documents"
    ]);
  }

  $reqStmt = $pdo->prepare("
    SELECT
      id,
      requirement_code,
      requirement_name,
      is_required,
      is_system,
      station_id,
      city_municipality,
      province,
      active
    FROM user_verification_requirements
    WHERE id = ?
      AND active = 1
    LIMIT 1
  ");
  $reqStmt->execute([$requirementId]);
  $requirement = $reqStmt->fetch(PDO::FETCH_ASSOC);

  if (!$requirement) {
    out(404, ["ok" => false, "message" => "Requirement not found or inactive"]);
  }

  if (!requirement_applies_to_user($pdo, $requirement, $profile)) {
    out(403, ["ok" => false, "message" => "This requirement does not apply to your account scope"]);
  }

  $pdo->beginTransaction();

  // Check if user already has a submission for this requirement
  $existingStmt = $pdo->prepare("
    SELECT
      id,
      status
    FROM user_requirement_submissions
    WHERE user_id = ?
      AND requirement_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $existingStmt->execute([$userId, $requirementId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $updateStmt = $pdo->prepare("
      UPDATE user_requirement_submissions
      SET
        file_name = ?,
        mime_type = ?,
        file_size = ?,
        document_blob = ?,
        status = 'submitted',
        remarks = NULL,
        uploaded_at = NOW(),
        reviewed_at = NULL,
        reviewed_by = NULL
      WHERE id = ?
    ");
    $updateStmt->bindParam(1, $fileName, PDO::PARAM_STR);
    $updateStmt->bindParam(2, $mimeType, PDO::PARAM_STR);
    $updateStmt->bindParam(3, $fileSize, PDO::PARAM_INT);
    $updateStmt->bindParam(4, $blob, PDO::PARAM_LOB);
    $updateStmt->bindParam(5, $existing["id"], PDO::PARAM_INT);
    $updateStmt->execute();

    $submissionId = (int)$existing["id"];
  } else {
    $insertStmt = $pdo->prepare("
      INSERT INTO user_requirement_submissions
      (
        user_id,
        requirement_id,
        file_name,
        mime_type,
        file_size,
        document_blob,
        status,
        remarks,
        uploaded_at
      )
      VALUES (?, ?, ?, ?, ?, ?, 'submitted', NULL, NOW())
    ");
    $insertStmt->bindParam(1, $userId, PDO::PARAM_INT);
    $insertStmt->bindParam(2, $requirementId, PDO::PARAM_INT);
    $insertStmt->bindParam(3, $fileName, PDO::PARAM_STR);
    $insertStmt->bindParam(4, $mimeType, PDO::PARAM_STR);
    $insertStmt->bindParam(5, $fileSize, PDO::PARAM_INT);
    $insertStmt->bindParam(6, $blob, PDO::PARAM_LOB);
    $insertStmt->execute();

    $submissionId = (int)$pdo->lastInsertId();
  }

  // If user was previously rejected/resubmission_required, keep them in incomplete until full submit step
  $currentStatus = strtolower((string)($user["account_status"] ?? "pending"));
  if (in_array($currentStatus, ["rejected", "resubmission_required"], true)) {
    $statusStmt = $pdo->prepare("
      UPDATE users
      SET
        account_status = 'incomplete',
        rejected_reason = NULL,
        updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $statusStmt->execute([$userId]);
  }

  $pdo->commit();

  $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
  $host = $_SERVER["HTTP_HOST"] ?? "";
  $baseUrl = $host !== "" ? $scheme . "://" . $host : "";
  $tokenParam = rawurlencode($token);
  $path = "/get_user_document.php?id=" . $submissionId . "&token=" . $tokenParam;

  out(200, [
    "ok" => true,
    "message" => "Document uploaded successfully",
    "requirement" => [
      "id" => (int)$requirement["id"],
      "code" => $requirement["requirement_code"],
      "name" => $requirement["requirement_name"],
      "is_required" => (int)$requirement["is_required"] === 1,
      "is_system" => (int)$requirement["is_system"] === 1,
    ],
    "submission" => [
      "id" => $submissionId,
      "user_id" => $userId,
      "requirement_id" => (int)$requirement["id"],
      "file_name" => $fileName,
      "mime_type" => $mimeType,
      "file_size" => $fileSize,
      "status" => "submitted",
      "uploaded_at" => date("Y-m-d H:i:s"),
      "preview_url" => $baseUrl . $path . "&mode=preview",
      "download_url" => $baseUrl . $path . "&mode=download",
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}