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

if ($user["role"] !== "admin" && $user["role"] !== "super_admin") {
  auth_out(403, ["ok" => false, "message" => "Admin or super admin access required"]);
}

if ($user["role"] === "admin") {
  $gate = auth_admin_station_gate($user);
  if ($gate !== null) {
    auth_out($gate["code"], $gate["payload"]);
  }
}

if ($user["role"] === "super_admin" && ($user["valid"] ?? "unvalid") !== "valid") {
  auth_out(403, ["ok" => false, "message" => "Super admin account is not valid"]);
}

$AUTH_USER = [
  "id" => (int)$user["id"],
  "role" => $user["role"],
  "station_id" => !empty($user["station_id"]) ? (int)$user["station_id"] : null,
  "station_name" => $user["station_name"] ?? null
];