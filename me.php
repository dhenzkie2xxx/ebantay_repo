<?php
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function resolve_token_from_request(): string {
  // 1) Try Bearer token first
  $token = bearer_token();
  if ($token !== "") return $token;

  // 2) Fallback to JSON body token for legacy/mobile clients
  $raw = file_get_contents("php://input");
  if ($raw !== "") {
    $data = json_decode($raw, true);
    if (is_array($data)) {
      $bodyToken = trim($data["token"] ?? "");
      if ($bodyToken !== "") return $bodyToken;
    }
  }

  return "";
}

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = resolve_token_from_request();
if ($token === "") {
  auth_out(401, ["ok" => false, "message" => "Missing token"]);
}

$user = auth_get_user_by_token($pdo, $token);
if (!$user) {
  auth_out(401, ["ok" => false, "message" => "Unauthorized"]);
}

if (auth_check_token_expired($user)) {
  auth_out(401, ["ok" => false, "message" => "Token expired"]);
}

auth_out(200, [
  "ok" => true,
  "user" => [
    "id" => (int)$user["id"],
    "firstname" => $user["firstname"],
    "lastname" => $user["lastname"],
    "email" => $user["email"],
    "username" => $user["username"],
    "role" => $user["role"],
    "station_id" => !empty($user["station_id"]) ? (int)$user["station_id"] : null,
    "station_name" => $user["station_name"] ?? null,
    "station_verification_status" => $user["station_verification_status"] ?? null,
    "account_status" => $user["account_status"] ?? null
  ]
]);