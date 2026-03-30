<?php
require_once __DIR__ . "/auth_helpers.php";

$token = bearer_token();
if ($token === "") {
  auth_out(401, ["ok" => false, "message" => "Missing Bearer token"]);
}

$user = auth_get_user_by_token($pdo, $token);
if (!$user) {
  auth_out(401, ["ok" => false, "message" => "Invalid token"]);
}

if (auth_check_token_expired($user)) {
  auth_out(401, ["ok" => false, "message" => "Token expired"]);
}

if ($user["role"] !== "admin") {
  auth_out(403, ["ok" => false, "message" => "Verified station admin access required"]);
}

$gate = auth_admin_station_gate($user);
if ($gate !== null) {
  auth_out($gate["code"], $gate["payload"]);
}

$AUTH_USER = array_merge([
  "id" => (int)$user["id"],
  "role" => $user["role"]
], auth_station_scope($user));