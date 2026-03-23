<?php
require_once __DIR__ . "/auth_helpers.php";

function profile_token_from_request(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

$token = profile_token_from_request();
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

try {
  $stmt = $pdo->prepare("
    SELECT profile_photo, profile_photo_mime, profile_photo_name
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$user["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row["profile_photo"])) {
    http_response_code(404);
    echo "Profile photo not found";
    exit;
  }

  header("Content-Type: " . ($row["profile_photo_mime"] ?: "application/octet-stream"));
  header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)($row["profile_photo_name"] ?: "profile")) . '"');
  echo $row["profile_photo"];
} catch (Throwable $e) {
  http_response_code(500);
  echo "Server error";
}