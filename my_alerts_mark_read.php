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

$data = json_decode(file_get_contents("php://input"), true);

$id = (int)($data["id"] ?? 0);
$ids = $data["ids"] ?? [];

if ($id > 0) {
  $ids[] = $id;
}

if (!is_array($ids)) {
  $ids = [];
}

$ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($v) => $v > 0)));

if (!$ids) {
  out(400, ["ok" => false, "message" => "Missing id"]);
}

$placeholders = implode(",", array_fill(0, count($ids), "?"));
$params = array_merge($ids, [$userId]);

$upd = $pdo->prepare("
  UPDATE notification_alerts
  SET is_read = 1
  WHERE id IN ($placeholders) AND user_id = ?
");
$upd->execute($params);

out(200, [
  "ok" => true,
  "message" => "Marked as read",
  "updated" => $upd->rowCount()
]);