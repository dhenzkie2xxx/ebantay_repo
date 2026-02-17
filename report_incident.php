<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = trim($_POST["token"] ?? "");
$title = trim($_POST["title"] ?? "");
$category = trim($_POST["category"] ?? "");
$description = trim($_POST["description"] ?? "");

$lat = $_POST["lat"] ?? null;
$lng = $_POST["lng"] ?? null;
$accuracy = $_POST["accuracy"] ?? null;
$deviceTime = trim($_POST["device_time"] ?? "");

$riskStatus = strtoupper(trim($_POST["risk_status"] ?? "SAFE"));
$riskDistanceM = $_POST["risk_distance_m"] ?? null;
$riskRadiusM = $_POST["risk_radius_m"] ?? 250;

if ($token === "" || $title === "" || $category === "" || $description === "" || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing/invalid fields"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

if (!in_array($riskStatus, ["SAFE", "RISK"], true)) {
  $riskStatus = "SAFE";
}

$riskRadiusM = is_numeric($riskRadiusM) ? (int)$riskRadiusM : 250;
$riskDistanceM = (is_numeric($riskDistanceM) ? (int)$riskDistanceM : null);
$accuracy = (is_numeric($accuracy) ? (int)round($accuracy) : null);

// Convert device_time (optional)
$deviceTimeSql = null;
if ($deviceTime !== "") {
  $t = strtotime($deviceTime);
  if ($t !== false) $deviceTimeSql = date("Y-m-d H:i:s", $t);
}

try {
  // Validate token (same pattern as your panic.php)
  $q = $pdo->prepare("SELECT id, api_token_expires FROM users WHERE api_token = ? LIMIT 1");
  $q->execute([$token]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) out(401, ["ok" => false, "message" => "Unauthorized"]);

  $exp = $user["api_token_expires"] ? strtotime($user["api_token_expires"]) : 0;
  if ($exp > 0 && time() > $exp) out(401, ["ok" => false, "message" => "Token expired"]);

  $pdo->beginTransaction();

  // Insert report
  $stmt = $pdo->prepare("
    INSERT INTO incident_reports
      (user_id, title, category, description, risk_status, risk_distance_m, risk_radius_m, lat, lng, accuracy_m, device_time)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stmt->execute([
    (int)$user["id"],
    $title,
    $category,
    $description,
    $riskStatus,
    $riskDistanceM,
    $riskRadiusM,
    (float)$lat,
    (float)$lng,
    $accuracy,
    $deviceTimeSql
  ]);

  $reportId = (int)$pdo->lastInsertId();

  // Handle photos (optional)
  // Photos come as photos[] in multipart.
  if (!empty($_FILES["photos"]) && is_array($_FILES["photos"]["tmp_name"])) {
    $count = count($_FILES["photos"]["tmp_name"]);

    // Optional: cap max photos server-side too
    $max = 5;
    if ($count > $max) $count = $max;

    $photoStmt = $pdo->prepare("
      INSERT INTO incident_report_photos (report_id, mime_type, file_name, file_size, image)
      VALUES (?, ?, ?, ?, ?)
    ");

    for ($i = 0; $i < $count; $i++) {
      $err = $_FILES["photos"]["error"][$i];
      if ($err !== UPLOAD_ERR_OK) continue;

      $tmp = $_FILES["photos"]["tmp_name"][$i];
      if (!is_uploaded_file($tmp)) continue;

      $size = (int)($_FILES["photos"]["size"][$i] ?? 0);
      $name = $_FILES["photos"]["name"][$i] ?? null;

      // Detect mime type (safer than trusting client)
      $mime = null;
      if (function_exists("finfo_open")) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mime = finfo_file($finfo, $tmp);
          finfo_close($finfo);
        }
      }
      if (!$mime) $mime = $_FILES["photos"]["type"][$i] ?? "application/octet-stream";

      // Allow only images
      if (strpos($mime, "image/") !== 0) continue;

      // Optional: max file size check (e.g., 6MB)
      $maxBytes = 6 * 1024 * 1024;
      if ($size > $maxBytes) continue;

      $bytes = file_get_contents($tmp);
      if ($bytes === false || $bytes === "") continue;

      // IMPORTANT: for BLOB with PDO, send as param; PDO will handle it
      $photoStmt->execute([
        $reportId,
        $mime,
        $name,
        $size,
        $bytes
      ]);
    }
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Report submitted",
    "report_id" => $reportId
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  out(500, ["ok" => false, "message" => "Server error"]);
}
