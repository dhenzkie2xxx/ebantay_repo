<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/audit_log_helper.php";

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

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

$token = bearer_token();

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

$lat = $data["lat"] ?? null;
$lng = $data["lng"] ?? null;
$accuracy = $data["accuracy_m"] ?? ($data["accuracy"] ?? null);
$dutyStatus = trim((string)($data["duty_status"] ?? "available"));

$allowedStatus = ["offline", "available", "busy", "enroute", "on_scene"];

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!in_array($dutyStatus, $allowedStatus, true)) {
  out(400, ["ok" => false, "message" => "Invalid duty status"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, ["ok" => false, "message" => "Coordinates out of range"]);
}

$accuracy = is_numeric($accuracy) ? (int)round((float)$accuracy) : null;

try {
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($user);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($user["role"] !== "police_on_field") {
    out(403, [
      "ok" => false,
      "message" => "Only Police on Field can update location."
    ]);
  }

  $oldDutyStatus = $user["duty_status"] ?? null;

  $pdo->beginTransaction();

  $insert = $pdo->prepare("
    INSERT INTO user_locations (
      user_id,
      lat,
      lng,
      accuracy_m,
      created_at
    )
    VALUES (?, ?, ?, ?, NOW())
  ");

  $insert->execute([
    (int)$user["id"],
    $lat,
    $lng,
    $accuracy
  ]);

  $update = $pdo->prepare("
    UPDATE users
    SET duty_status = ?,
        last_seen_at = NOW()
    WHERE id = ?
      AND role = 'police_on_field'
  ");

  $update->execute([
    $dutyStatus,
    (int)$user["id"]
  ]);

  if ($oldDutyStatus !== $dutyStatus) {
    write_audit_log(
      $pdo,
      $user,
      "POLICE_DUTY_STATUS_UPDATED",
      "user",
      (int)$user["id"],
      "Police on Field updated duty status.",
      [
        "module" => "police_on_field",
        "target_user_id" => (int)$user["id"],
        "old_values" => [
          "duty_status" => $oldDutyStatus
        ],
        "new_values" => [
          "duty_status" => $dutyStatus,
          "lat" => $lat,
          "lng" => $lng,
          "accuracy_m" => $accuracy
        ]
      ]
    );
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Location updated successfully.",
    "duty_status" => $dutyStatus
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}