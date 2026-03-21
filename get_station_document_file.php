<?php
require_once __DIR__ . "/auth_helpers.php";

function file_token_from_request(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

$token = file_token_from_request();
if ($token === "") {
  http_response_code(401);
  echo "Missing token";
  exit;
}

$user = auth_get_user_by_token($pdo, $token);
if (!$user) {
  http_response_code(401);
  echo "Invalid token";
  exit;
}

if (auth_check_token_expired($user)) {
  http_response_code(401);
  echo "Token expired";
  exit;
}

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

  $isSuper = (($user["role"] ?? "") === "super_admin");
  $isOwnerAdmin = (($user["role"] ?? "") === "admin" && (int)$file["created_by"] === (int)$user["id"]);

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