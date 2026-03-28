<?php
require_once __DIR__ . "/auth_helpers.php";

function file_token_from_request(): string {
  $token = bearer_token();
  if ($token !== "") {
    return $token;
  }

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") {
    return $queryToken;
  }

  return "";
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "Method not allowed";
  exit;
}

$token = file_token_from_request();
if ($token === "") {
  http_response_code(401);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "Missing token";
  exit;
}

$user = auth_get_user_by_token($pdo, $token);
if (!$user) {
  http_response_code(401);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "Invalid token";
  exit;
}

if (auth_check_token_expired($user)) {
  http_response_code(401);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "Token expired";
  exit;
}

$documentId = (int)($_GET["id"] ?? 0);
if ($documentId <= 0) {
  http_response_code(400);
  header("Content-Type: text/plain; charset=UTF-8");
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
      d.file_size,
      d.file_ext,
      ps.created_by
    FROM police_station_documents d
    INNER JOIN police_stations ps ON ps.id = d.station_id
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->execute([$documentId]);
  $file = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$file) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "File not found";
    exit;
  }

  $role = (string)($user["role"] ?? "");
  $isSuper = ($role === "super_admin");
  $isOwnerAdmin = ($role === "admin" && (int)$file["created_by"] === (int)$user["id"]);

  if (!$isSuper && !$isOwnerAdmin) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Access denied";
    exit;
  }

  $mimeType = trim((string)($file["mime_type"] ?? ""));
  if ($mimeType === "") {
    $mimeType = "application/octet-stream";
  }

  $fileName = trim((string)($file["file_name"] ?? ""));
  if ($fileName === "") {
    $fallbackExt = trim((string)($file["file_ext"] ?? ""));
    $fileName = "station-document" . ($fallbackExt !== "" ? "." . $fallbackExt : "");
  }

  $safeFileName = str_replace(["\r", "\n", '"'], ["", "", ""], $fileName);
  $fileData = $file["file_data"] ?? null;

  if ($fileData === null) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "File content not found";
    exit;
  }

  $contentLength = strlen($fileData);

  header("Content-Type: " . $mimeType);
  header('Content-Disposition: inline; filename="' . $safeFileName . '"');
  header("Content-Length: " . $contentLength);
  header("X-Content-Type-Options: nosniff");
  header("Cache-Control: private, max-age=300");

  echo $fileData;
  exit;
} catch (Throwable $e) {
  http_response_code(500);
  header("Content-Type: text/plain; charset=UTF-8");
  echo "Server error";
  exit;
}