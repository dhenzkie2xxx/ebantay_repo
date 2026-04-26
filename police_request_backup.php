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

$assignmentId = $data["assignment_id"] ?? null;
$reason = trim((string)($data["reason"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($assignmentId) || (int)$assignmentId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid assignment ID"]);
}

$assignmentId = (int)$assignmentId;

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
      "message" => "Only Police on Field can request backup."
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      source_type,
      source_id,
      assigned_user_id,
      assigned_station_id,
      authorization_status,
      status,
      backup_requested
    FROM responder_assignments
    WHERE id = ?
      AND assigned_user_id = ?
      AND assigned_station_id = ?
      AND status <> 'cancelled'
    LIMIT 1
  ");

  $stmt->execute([
    $assignmentId,
    (int)$police["id"],
    (int)$police["station_id"]
  ]);

  $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$assignment) {
    out(404, [
      "ok" => false,
      "message" => "Assignment not found."
    ]);
  }

  if (!in_array($assignment["authorization_status"], ["go_signal_sent", "approved_to_proceed"], true)) {
    out(403, [
      "ok" => false,
      "message" => "You are not yet authorized to request backup for this assignment.",
      "authorization_status" => $assignment["authorization_status"]
    ]);
  }

  if ((int)$assignment["backup_requested"] === 1) {
    out(409, [
      "ok" => false,
      "message" => "Backup was already requested for this assignment."
    ]);
  }

  $pdo->beginTransaction();

  $upd = $pdo->prepare("
    UPDATE responder_assignments
    SET backup_requested = 1,
        backup_requested_at = NOW(),
        notes = CONCAT(COALESCE(notes, ''), '\nBackup requested: ', ?)
    WHERE id = ?
  ");

  $upd->execute([
    $reason !== "" ? $reason : "No reason provided.",
    $assignmentId
  ]);

  $adminStmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE role = 'admin'
      AND station_id = ?
      AND account_status = 'active'
      AND valid = 'valid'
    LIMIT 1
  ");

  $adminStmt->execute([
    (int)$police["station_id"]
  ]);

  $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

  if ($admin) {
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
      VALUES (?, 'BACKUP_REQUEST', ?, ?, ?, 'HIGH', 0)
    ");

    $notif->execute([
      (int)$admin["id"],
      "Backup Requested",
      "Police on Field requested backup. " . ($reason !== "" ? $reason : ""),
      $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null
    ]);
  }

  write_audit_log(
    $pdo,
    $police,
    "BACKUP_REQUESTED",
    "responder_assignment",
    $assignmentId,
    "Police on Field requested backup.",
    [
      "assignment_id" => $assignmentId,
      "incident_id" => $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null,
      "panic_id" => $assignment["source_type"] === "panic" ? (int)$assignment["source_id"] : null,
      "target_user_id" => $admin ? (int)$admin["id"] : null
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Backup request sent to Station Admin.",
    "assignment_id" => $assignmentId,
    "backup_requested" => true,
    "backup_requested_at" => date("Y-m-d H:i:s")
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