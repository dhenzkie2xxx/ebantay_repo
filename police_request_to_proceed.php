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

$sourceType = trim((string)($data["source_type"] ?? ""));
$sourceId = $data["source_id"] ?? null;
$notes = trim((string)($data["notes"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!in_array($sourceType, ["incident", "panic"], true)) {
  out(400, ["ok" => false, "message" => "Invalid source type"]);
}

if (!is_numeric($sourceId) || (int)$sourceId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid source ID"]);
}

$sourceId = (int)$sourceId;

try {
  $police = auth_get_user_by_token($pdo, $token);

  if (!$police) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($police)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($police);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($police["role"] !== "police_on_field") {
    out(403, [
      "ok" => false,
      "message" => "Only Police on Field can request confirmation to proceed."
    ]);
  }

  $stationId = (int)$police["station_id"];

  if ($sourceType === "incident") {
    $srcStmt = $pdo->prepare("
      SELECT id, assigned_station_id
      FROM incident_reports
      WHERE id = ?
        AND assigned_station_id = ?
      LIMIT 1
    ");
  } else {
    $srcStmt = $pdo->prepare("
      SELECT id, assigned_station_id
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
      "message" => "Report/panic request not found under your assigned station."
    ]);
  }

  $pdo->beginTransaction();

  $existingStmt = $pdo->prepare("
    SELECT
      id,
      source_type,
      source_id,
      assigned_user_id,
      assigned_station_id,
      assignment_role,
      status,
      authorization_status,
      proceed_requested_at,
      notes
    FROM responder_assignments
    WHERE source_type = ?
      AND source_id = ?
      AND assigned_user_id = ?
      AND assignment_role = 'PRIMARY'
      AND status <> 'cancelled'
    LIMIT 1
  ");

  $existingStmt->execute([
    $sourceType,
    $sourceId,
    (int)$police["id"]
  ]);

  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $assignmentId = (int)$existing["id"];

    $upd = $pdo->prepare("
      UPDATE responder_assignments
      SET authorization_status = 'requested_to_proceed',
          proceed_requested_at = NOW(),
          notes = ?
      WHERE id = ?
    ");

    $upd->execute([
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
        proceed_requested_at,
        notes
      )
      VALUES (?, ?, ?, ?, 'PRIMARY', 'pending', 'requested_to_proceed', NOW(), ?)
    ");

    $ins->execute([
      $sourceType,
      $sourceId,
      (int)$police["id"],
      $stationId,
      $notes !== "" ? $notes : null
    ]);

    $assignmentId = (int)$pdo->lastInsertId();
  }

  $pdo->prepare("
    INSERT INTO notification_alerts (
      user_id,
      type,
      title,
      message,
      incident_id,
      severity,
      is_read
    )
    SELECT
      u.id,
      'PROCEED_REQUEST',
      'Proceed Request Received',
      'Police on Field requested permission to proceed.',
      ?,
      'HIGH',
      0
    FROM users u
    WHERE u.role = 'admin'
      AND u.station_id = ?
      AND u.account_status = 'active'
  ")->execute([
    $sourceType === "incident" ? $sourceId : null,
    $stationId
  ]);

  write_audit_log(
    $pdo,
    $police,
    "PROCEED_REQUEST_RECEIVED",
    "responder_assignment",
    $assignmentId,
    "Police on Field requested permission to proceed.",
    [
      "module" => "dispatch_queue",
      "assignment_id" => $assignmentId,
      "incident_id" => $sourceType === "incident" ? $sourceId : null,
      "panic_id" => $sourceType === "panic" ? $sourceId : null,
      "target_user_id" => (int)$police["id"],
      "old_values" => $existing ?: null,
      "new_values" => [
        "source_type" => $sourceType,
        "source_id" => $sourceId,
        "assigned_user_id" => (int)$police["id"],
        "assigned_station_id" => $stationId,
        "assignment_role" => "PRIMARY",
        "authorization_status" => "requested_to_proceed",
        "notes" => $notes !== "" ? $notes : null
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Request to proceed submitted successfully.",
    "assignment_id" => $assignmentId,
    "authorization_status" => "requested_to_proceed"
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