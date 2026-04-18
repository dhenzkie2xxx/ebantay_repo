<?php
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function bearer_token(): string {
  $h =
    $_SERVER["HTTP_AUTHORIZATION"] ??
    $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ??
    "";

  if ($h === "" && function_exists("getallheaders")) {
    $headers = getallheaders();
    if (isset($headers["Authorization"])) $h = $headers["Authorization"];
    elseif (isset($headers["authorization"])) $h = $headers["authorization"];
  }

  if (!$h) return "";
  if (stripos($h, "Bearer ") !== 0) return "";
  return trim(substr($h, 7));
}

$token = bearer_token();
if ($token === "") out(401, ["ok" => false, "message" => "Missing token"]);

$stmt = $pdo->prepare("
  SELECT id, role, account_status, valid
  FROM users
  WHERE api_token = ?
    AND (api_token_expires IS NULL OR api_token_expires > NOW())
  LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  out(401, ["ok" => false, "message" => "Invalid token"]);
}

if (strtolower((string)($user["role"] ?? "")) !== "citizen") {
  out(403, ["ok" => false, "message" => "Access denied"]);
}

$userId = (int)$user["id"];

$countStmt = $pdo->prepare("
  SELECT COUNT(*) AS unread_count
  FROM notification_alerts
  WHERE user_id = ?
    AND is_read = 0
");
$countStmt->execute([$userId]);
$unreadCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)["unread_count"] ?? 0);

$list = $pdo->prepare("
  SELECT id, type, title, message, hotspot_id, incident_id, severity, is_read, created_at
  FROM notification_alerts
  WHERE user_id = ?
  ORDER BY created_at DESC, id DESC
  LIMIT 50
");
$list->execute([$userId]);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "unread_count" => $unreadCount,
  "alerts" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "type" => $r["type"],
      "title" => $r["title"],
      "message" => $r["message"],
      "hotspot_id" => $r["hotspot_id"] !== null ? (int)$r["hotspot_id"] : null,
      "incident_id" => $r["incident_id"] !== null ? (int)$r["incident_id"] : null,
      "severity" => $r["severity"],
      "is_read" => (int)$r["is_read"],
      "created_at" => $r["created_at"]
    ];
  }, $rows),
  "user" => [
    "id" => $userId,
    "account_status" => $user["account_status"] ?? null,
    "valid" => $user["valid"] ?? null,
  ]
]);