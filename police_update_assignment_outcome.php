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
$outcome = strtoupper(trim((string)($data["outcome"] ?? "")));
$notes = trim((string)($data["notes"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($assignmentId) || (int)$assignmentId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid assignment ID"]);
}

if (!in_array($outcome, ["VERIFIED", "FALSE_REPORT", "RESOLVED"], true)) {
  out(400, [
    "ok" => false,
    "message" => "Invalid outcome. Use VERIFIED, FALSE_REPORT, or RESOLVED."
  ]);
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
      "message" => "Only Police on Field can update assignment outcome."
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
      outcome
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
      "message" => "You are not yet authorized to update this report outcome.",
      "authorization_status" => $assignment["authorization_status"]
    ]);
  }

  $pdo->beginTransaction();

  $updAssignment = $pdo->prepare("
    UPDATE responder_assignments
    SET status = 'resolved',
        resolved_at = NOW(),
        outcome = ?,
        outcome_at = NOW(),
        outcome_notes = ?
    WHERE id = ?
  ");

  $updAssignment->execute([
    $outcome,
    $notes !== "" ? $notes : null,
    $assignmentId
  ]);

  if ($assignment["source_type"] === "incident") {
    if ($outcome === "VERIFIED") {
      $pdo->prepare("
        UPDATE incident_reports
        SET verification_status = 'VERIFIED',
            incident_phase = 'UNDER_INVESTIGATION',
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ?
      ")->execute([
        (int)$police["id"],
        (int)$assignment["source_id"]
      ]);
    }

    if ($outcome === "FALSE_REPORT") {
      $pdo->prepare("
        UPDATE incident_reports
        SET verification_status = 'FALSE_REPORT',
            incident_phase = 'REJECTED',
            reviewed_by = ?,
            reviewed_at = NOW(),
            resolved_at = NOW(),
            admin_notes = ?
        WHERE id = ?
      ")->execute([
        (int)$police["id"],
        $notes !== "" ? $notes : "Marked as false report by Police on Field.",
        (int)$assignment["source_id"]
      ]);
    }

    if ($outcome === "RESOLVED") {
      $pdo->prepare("
        UPDATE incident_reports
        SET verification_status = 'VERIFIED',
            incident_phase = 'RESOLVED',
            case_status = 'CLOSED',
            reviewed_by = ?,
            reviewed_at = NOW(),
            resolved_at = NOW(),
            admin_notes = ?
        WHERE id = ?
      ")->execute([
        (int)$police["id"],
        $notes !== "" ? $notes : "Resolved by Police on Field.",
        (int)$assignment["source_id"]
      ]);
    }
  }

  if ($assignment["source_type"] === "panic") {
    if ($outcome === "FALSE_REPORT") {
      $panicStatus = "false_alarm";
    } else {
      $panicStatus = "resolved";
    }

    $pdo->prepare("
      UPDATE panic_requests
      SET status = ?
      WHERE id = ?
    ")->execute([
      $panicStatus,
      (int)$assignment["source_id"]
    ]);
  }

  $pdo->prepare("
    UPDATE users
    SET duty_status = 'available',
        last_seen_at = NOW()
    WHERE id = ?
      AND role = 'police_on_field'
  ")->execute([
    (int)$police["id"]
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
      VALUES (?, 'ASSIGNMENT_OUTCOME', ?, ?, ?, 'HIGH', 0)
    ");

    $notif->execute([
      (int)$admin["id"],
      "Police Assignment Outcome Updated",
      "Police on Field updated the assignment outcome to " . $outcome . ".",
      $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null
    ]);
  }

  $auditAction = "ASSIGNMENT_RESOLVED";

  if ($outcome === "FALSE_REPORT" && $assignment["source_type"] === "incident") {
    $auditAction = "FALSE_REPORT_MARKED";
  }

  if ($outcome === "FALSE_REPORT" && $assignment["source_type"] === "panic") {
    $auditAction = "FALSE_ALARM_MARKED";
  }

  write_audit_log(
    $pdo,
    $police,
    $auditAction,
    "responder_assignment",
    $assignmentId,
    "Police on Field updated assignment outcome to " . $outcome . ".",
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
    "message" => "Assignment outcome updated successfully.",
    "assignment_id" => $assignmentId,
    "source_type" => $assignment["source_type"],
    "source_id" => (int)$assignment["source_id"],
    "outcome" => $outcome
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