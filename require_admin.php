<?php
require_once __DIR__ . "/db.php";

function json_out($code, $payload) {
  http_response_code($code);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($payload);
  exit;
}

function get_bearer_token(): string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$hdr) return '';
  if (stripos($hdr, 'Bearer ') !== 0) return '';
  return trim(substr($hdr, 7));
}

$token = get_bearer_token();
if ($token === '') {
  json_out(401, ["ok"=>false, "message"=>"Missing Authorization Bearer token"]);
}

$stmt = $pdo->prepare("
  SELECT id, role, valid, api_token_expires
  FROM users
  WHERE api_token = ?
  LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  json_out(401, ["ok"=>false, "message"=>"Invalid token"]);
}

if (($user["api_token_expires"] ?? null) && strtotime($user["api_token_expires"]) < time()) {
  json_out(401, ["ok"=>false, "message"=>"Token expired"]);
}

if ($user["role"] !== "admin") {
  json_out(403, ["ok"=>false, "message"=>"Admin access required"]);
}

if ($user["valid"] !== "valid") {
  json_out(403, ["ok"=>false, "message"=>"Account is not valid"]);
}

// If you want the current admin user in endpoints:
$AUTH_USER = [
  "id" => (int)$user["id"],
  "role" => $user["role"],
];