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
$decision = trim((string)($data["decision"] ?? "approve"));
$notes = trim((string)($data["notes"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($assignmentId) || (int)$assignmentId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid assignment ID"]);
}

if (!in_array($decision, ["approve", "deny"], true)) {
  out(400, ["ok" => false, "message" => "Invalid decision"]);
}

$assignmentId = (int)$assignmentId;

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
      "message" => "Only Station Admin can approve or deny proceed requests."
    ]);
  }

  $stationId = (int)$admin["station_id"];

  $stmt = $pdo->prepare("
    SELECT
      ra.id,
      ra.source_type,
      ra.source_id,
      ra.assigned_user_id,
      ra.assigned_station_id,
      ra.authorization_status,
      ra.status,
      ra.notes,
      ra.detected_distance_m,
      ra.proceed_requested_at,
      ra.authorized_by,
      ra.authorized_at,

      u.firstname,
      u.lastname,
      u.duty_status
    FROM responder_assignments ra
    INNER JOIN users u ON u.id = ra.assigned_user_id
    WHERE ra.id = ?
      AND ra.assigned_station_id = ?
      AND ra.authorization_status = 'requested_to_proceed'
      AND ra.status <> 'cancelled'
      AND u.role = 'police_on_field'
    LIMIT 1
  ");

  $stmt->execute([$assignmentId, $stationId]);
  $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$assignment) {
    out(404, [
      "ok" => false,
      "message" => "Proceed request not found or already handled."
    ]);
  }

  $pdo->beginTransaction();

  if ($decision === "approve") {
    $newAuthStatus = "approved_to_proceed";
    $newAssignmentStatus = "ack";
    $policeDutyStatus = "enroute";
    $notifTitle = "Proceed Request Approved";
    $notifMessage = "Station Admin approved your request. You may now proceed.";
  } else {
    $newAuthStatus = "denied";
    $newAssignmentStatus = "cancelled";
    $policeDutyStatus = "available";
    $notifTitle = "Proceed Request Denied";
    $notifMessage = "Station Admin denied your request to proceed.";
  }

  $update = $pdo->prepare("
    UPDATE responder_assignments
    SET authorization_status = ?,
        status = ?,
        authorized_by = ?,
        authorized_at = NOW(),
        notes = ?
    WHERE id = ?
  ");

  $update->execute([
    $newAuthStatus,
    $newAssignmentStatus,
    (int)$admin["id"],
    $notes !== "" ? $notes : null,
    $assignmentId
  ]);

  $pdo->prepare("
    UPDATE users
    SET duty_status = ?,
        last_seen_at = NOW()
    WHERE id = ?
      AND role = 'police_on_field'
  ")->execute([
    $policeDutyStatus,
    (int)$assignment["assigned_user_id"]
  ]);

  if ($decision === "approve") {
    if ($assignment["source_type"] === "incident") {
      $pdo->prepare("
        UPDATE incident_reports
        SET incident_phase = 'UNDER_VERIFICATION',
            reviewed_by = ?,
            reviewed_at = NOW()
        WHERE id = ?
      ")->execute([
        (int)$admin["id"],
        (int)$assignment["source_id"]
      ]);
    } else {
      $pdo->prepare("
        UPDATE panic_requests
        SET status = 'ack'
        WHERE id = ?
      ")->execute([
        (int)$assignment["source_id"]
      ]);
    }
  }

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
    VALUES (?, ?, ?, ?, ?, 'HIGH', 0)
  ");

  $notif->execute([
    (int)$assignment["assigned_user_id"],
    $decision === "approve" ? "PROCEED_APPROVED" : "PROCEED_DENIED",
    $notifTitle,
    $notifMessage,
    $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null
  ]);

  write_audit_log(
    $pdo,
    $admin,
    $decision === "approve" ? "PROCEED_APPROVED" : "PROCEED_DENIED",
    "responder_assignment",
    $assignmentId,
    $decision === "approve"
      ? "Station Admin approved Police on Field request to proceed."
      : "Station Admin denied Police on Field request to proceed.",
    [
      "module" => "dispatch_queue",
      "assignment_id" => $assignmentId,
      "incident_id" => $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null,
      "panic_id" => $assignment["source_type"] === "panic" ? (int)$assignment["source_id"] : null,
      "target_user_id" => (int)$assignment["assigned_user_id"],
      "old_values" => $assignment,
      "new_values" => [
        "authorization_status" => $newAuthStatus,
        "status" => $newAssignmentStatus,
        "authorized_by" => (int)$admin["id"],
        "notes" => $notes !== "" ? $notes : null,
        "police_duty_status" => $policeDutyStatus
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => $decision === "approve"
      ? "Police on Field approved to proceed."
      : "Police on Field request denied.",
    "assignment_id" => $assignmentId,
    "decision" => $decision,
    "authorization_status" => $newAuthStatus
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