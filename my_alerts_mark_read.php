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
if ($token === "") out(401, ["ok"=>false, "message"=>"Missing token"]);

$stmt = $pdo->prepare("
  SELECT id
  FROM users
  WHERE api_token = ?
    AND valid = 'valid'
  LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) out(401, ["ok"=>false, "message"=>"Invalid token"]);

$userId = (int)$user["id"];

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data["id"] ?? 0);

if ($id <= 0) {
  out(400, ["ok"=>false, "message"=>"Missing id"]);
}

$upd = $pdo->prepare("
  UPDATE notification_alerts
  SET is_read = 1
  WHERE id = ? AND user_id = ?
");
$upd->execute([$id, $userId]);

out(200, ["ok"=>true, "message"=>"Marked as read"]);