<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";

function out($code, $payload) {
  http_response_code($code);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($payload);
  exit;
}

function bearer_token(): string {
  $h =
    $_SERVER["HTTP_AUTHORIZATION"] ??
    $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ??
    $_SERVER["Authorization"] ?? // sometimes present
    "";

  // Some servers place it in getallheaders()
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
if ($token === "") out(401, [
  "ok"=>false,
  "message"=>"Missing Bearer token",
  "debug_auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? null
]);

$stmt = $pdo->prepare("
  SELECT id, role, valid, api_token_expires
  FROM users
  WHERE api_token = ?
  LIMIT 1
");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) out(401, ["ok"=>false, "message"=>"Invalid token"]);

if (!empty($user["api_token_expires"]) && strtotime($user["api_token_expires"]) < time()) {
  out(401, ["ok"=>false, "message"=>"Token expired"]);
}

if ($user["role"] !== "admin") out(403, ["ok"=>false, "message"=>"Admin access required"]);
if (($user["valid"] ?? "valid") !== "valid") out(403, ["ok"=>false, "message"=>"Account not valid"]);

$AUTH_USER = [
  "id" => (int)$user["id"],
  "role" => $user["role"]
];