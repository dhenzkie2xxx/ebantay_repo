<?php
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function resolve_token_from_request(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

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
  "user" => array_merge([
    "id" => (int)$user["id"],
    "firstname" => $user["firstname"],
    "lastname" => $user["lastname"],
    "email" => $user["email"],
    "username" => $user["username"],
    "role" => $user["role"],
    "valid" => $user["valid"] ?? null,
    "account_status" => $user["account_status"] ?? null,
    "account_flag_status" => $user["account_flag_status"] ?? "none",
    "false_report_count" => isset($user["false_report_count"]) ? (int)$user["false_report_count"] : 0,
    "false_alarm_count" => isset($user["false_alarm_count"]) ? (int)$user["false_alarm_count"] : 0,
    "flagged_reason" => $user["flagged_reason"] ?? null,
    "flagged_at" => $user["flagged_at"] ?? null,
    "suspended_at" => $user["suspended_at"] ?? null,
    "suspension_reason" => $user["suspension_reason"] ?? null
  ], auth_station_scope($user))
]);