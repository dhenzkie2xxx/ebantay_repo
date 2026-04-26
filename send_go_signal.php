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

function distance_meters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a =
    sin($dLat / 2) * sin($dLat / 2) +
    cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

$sourceType = trim((string)($data["source_type"] ?? ""));
$sourceId = $data["source_id"] ?? null;
$policeUserId = $data["police_user_id"] ?? null;
$notes = trim((string)($data["notes"] ?? ""));

if (!in_array($sourceType, ["incident", "panic"], true)) {
  out(400, ["ok" => false, "message" => "Invalid source type"]);
}

if (!is_numeric($sourceId) || (int)$sourceId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid source ID"]);
}

if (!is_numeric($policeUserId) || (int)$policeUserId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid Police on Field user ID"]);
}

$sourceId = (int)$sourceId;
$policeUserId = (int)$policeUserId;

try {
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can send Go Signal."
    ]);
  }

  $stationId = (int)$admin["station_id"];

  $policeStmt = $pdo->prepare("
    SELECT
      id,
      firstname,
      lastname,
      station_id,
      duty_status,
      account_status,
      valid,
      is_email_verified,
      account_flag_status
    FROM users
    WHERE id = ?
      AND role = 'police_on_field'
      AND station_id = ?
    LIMIT 1
  ");
  $policeStmt->execute([$policeUserId, $stationId]);
  $police = $policeStmt->fetch(PDO::FETCH_ASSOC);

  if (!$police) {
    out(404, [
      "ok" => false,
      "message" => "Police on Field not found under your station."
    ]);
  }

  if (
    $police["valid"] !== "valid" ||
    $police["account_status"] !== "active" ||
    (int)$police["is_email_verified"] !== 1 ||
    strtolower((string)$police["account_flag_status"]) === "suspended"
  ) {
    out(403, [
      "ok" => false,
      "message" => "Selected Police on Field account is not active/valid."
    ]);
  }

  if ($police["duty_status"] !== "available") {
    out(409, [
      "ok" => false,
      "message" => "Selected Police on Field is not currently available.",
      "duty_status" => $police["duty_status"]
    ]);
  }

  if ($sourceType === "incident") {
    $srcStmt = $pdo->prepare("
      SELECT id, title, incident_type, lat, lng, assigned_station_id
      FROM incident_reports
      WHERE id = ?
        AND assigned_station_id = ?
      LIMIT 1
    ");
  } else {
    $srcStmt = $pdo->prepare("
      SELECT id, level, lat, lng, assigned_station_id
      FROM panic_requests
      WHERE id = ?
        AND assigned_station_id = ?
      LIMIT 1
    ");
  }

  $srcStmt->execute([$sourceId, $stationId]);
  $source = $srcStmt->fetch(PDO::FETCH_ASSOC);

  if (!$source) {
    out(404, [
      "ok" => false,
      "message" => "Report/panic request not found under your station."
    ]);
  }

  $locStmt = $pdo->prepare("
    SELECT lat, lng, accuracy_m, created_at
    FROM user_locations
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $locStmt->execute([$policeUserId]);
  $loc = $locStmt->fetch(PDO::FETCH_ASSOC);

  $distanceM = null;

  if ($loc && is_numeric($loc["lat"]) && is_numeric($loc["lng"])) {
    $distanceM = (int)round(distance_meters(
      (float)$source["lat"],
      (float)$source["lng"],
      (float)$loc["lat"],
      (float)$loc["lng"]
    ));
  }

  $pdo->beginTransaction();

  $existingStmt = $pdo->prepare("
    SELECT id
    FROM responder_assignments
    WHERE source_type = ?
      AND source_id = ?
      AND assigned_user_id = ?
      AND assignment_role = 'PRIMARY'
      AND status <> 'cancelled'
    LIMIT 1
  ");
  $existingStmt->execute([$sourceType, $sourceId, $policeUserId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $assignmentId = (int)$existing["id"];

    $upd = $pdo->prepare("
      UPDATE responder_assignments
      SET
        status = 'ack',
        authorization_status = 'go_signal_sent',
        authorized_by = ?,
        authorized_at = NOW(),
        detected_distance_m = ?,
        notes = ?
      WHERE id = ?
    ");
    $upd->execute([
      (int)$admin["id"],
      $distanceM,
      $notes !== "" ? $notes : null,
      $assignmentId
    ]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO responder_assignments (
        source_type,
        source_id,
        assigned_user_id,
        assigned_station_id,
        assignment_role,
        status,
        authorization_status,
        authorized_by,
        authorized_at,
        detected_distance_m,
        notes
      )
      VALUES (?, ?, ?, ?, 'PRIMARY', 'ack', 'go_signal_sent', ?, NOW(), ?, ?)
    ");

    $ins->execute([
      $sourceType,
      $sourceId,
      $policeUserId,
      $stationId,
      (int)$admin["id"],
      $distanceM,
      $notes !== "" ? $notes : null
    ]);

    $assignmentId = (int)$pdo->lastInsertId();
  }

  $pdo->prepare("
    UPDATE users
    SET duty_status = 'enroute',
        last_seen_at = NOW()
    WHERE id = ?
  ")->execute([$policeUserId]);

  if ($sourceType === "incident") {
    $pdo->prepare("
      UPDATE incident_reports
      SET incident_phase = 'UNDER_VERIFICATION',
          reviewed_by = ?,
          reviewed_at = NOW()
      WHERE id = ?
    ")->execute([(int)$admin["id"], $sourceId]);
  } else {
    $pdo->prepare("
      UPDATE panic_requests
      SET status = 'ack'
      WHERE id = ?
    ")->execute([$sourceId]);
  }

  $title = $sourceType === "incident"
    ? "Go Signal: Incident Response"
    : "Go Signal: Panic Response";

  $message = $sourceType === "incident"
    ? "You are authorized to proceed and verify the incident report."
    : "You are authorized to proceed and respond to the panic request.";

  $notif = $pdo->prepare("
    INSERT INTO notification_alerts (
      user_id,
      type,
      title,
      message,
      incident_id,
      severity,
      is_read
    )
    VALUES (?, 'GO_SIGNAL', ?, ?, ?, 'HIGH', 0)
  ");

  $notif->execute([
    $policeUserId,
    $title,
    $message,
    $sourceType === "incident" ? $sourceId : null
  ]);

  write_audit_log(
    $pdo,
    $admin,
    "GO_SIGNAL_SENT",
    "responder_assignment",
    $assignmentId,
    "Station Admin sent Go Signal to Police on Field.",
    [
      "assignment_id" => $assignmentId,
      "incident_id" => $sourceType === "incident" ? $sourceId : null,
      "panic_id" => $sourceType === "panic" ? $sourceId : null,
      "target_user_id" => $policeUserId
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Go Signal sent successfully.",
    "assignment_id" => $assignmentId,
    "source_type" => $sourceType,
    "source_id" => $sourceId,
    "police_user_id" => $policeUserId,
    "authorization_status" => "go_signal_sent",
    "distance_m" => $distanceM
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