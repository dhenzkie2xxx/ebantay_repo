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

if (($user["role"] ?? "") !== "admin") {
  auth_out(403, ["ok" => false, "message" => "Admin access required"]);
}

if ((int)($user["is_email_verified"] ?? 0) !== 1) {
  auth_out(403, [
    "ok" => false,
    "code" => "EMAIL_NOT_VERIFIED",
    "message" => "Please verify your email to continue."
  ]);
}

if (($user["account_status"] ?? "") === "disabled") {
  auth_out(403, [
    "ok" => false,
    "code" => "ACCOUNT_DISABLED",
    "message" => "Your admin account is disabled."
  ]);
}

$AUTH_USER = [
  "id" => (int)$user["id"],
  "role" => $user["role"],
  "station_id" => !empty($user["station_id"]) ? (int)$user["station_id"] : null,
  "station_name" => $user["station_name"] ?? null,
  "station_verification_status" => $user["station_verification_status"] ?? null,
  "account_status" => $user["account_status"] ?? null
];