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

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$token = trim($data["token"] ?? "");
if ($token === "") out(400, ["ok" => false, "message" => "Missing token"]);

$q = $pdo->prepare("SELECT id, firstname, lastname, username, role, api_token_expires
                    FROM users WHERE api_token = ? LIMIT 1");
$q->execute([$token]);
$user = $q->fetch(PDO::FETCH_ASSOC);

if (!$user) out(401, ["ok" => false, "message" => "Unauthorized"]);

$exp = $user["api_token_expires"] ? strtotime($user["api_token_expires"]) : 0;
if ($exp > 0 && time() > $exp) out(401, ["ok" => false, "message" => "Token expired"]);

out(200, [
  "ok" => true,
  "user" => [
    "id" => (int)$user["id"],
    "firstname" => $user["firstname"],
    "lastname" => $user["lastname"],
    "username" => $user["username"],
    "role" => $user["role"],
  ]
]);
