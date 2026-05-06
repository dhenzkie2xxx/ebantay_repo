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

$token = bearer_token();
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

$assignmentId = $data["assignment_id"] ?? null;
$response = strtolower(trim((string)($data["response"] ?? "")));
$notes = trim((string)($data["notes"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($assignmentId) || (int)$assignmentId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid assignment ID"]);
}

if (!in_array($response, ["approved", "denied"], true)) {
  out(400, ["ok" => false, "message" => "Invalid backup response"]);
}

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
      "message" => "Only Station Admin can respond to backup requests."
    ]);
  }

  $assignmentId = (int)$assignmentId;
  $stationId = (int)$admin["station_id"];

  $stmt = $pdo->prepare("
    SELECT
      id,
      source_type,
      source_id,
      assigned_user_id,
      assigned_station_id,
      status,
      authorization_status,
      backup_requested,
      backup_requested_at,
      backup_admin_response,
      backup_response_notes,
      backup_responded_by,
      backup_responded_at
    FROM responder_assignments
    WHERE id = ?
      AND assigned_station_id = ?
      AND status <> 'cancelled'
    LIMIT 1
  ");

  $stmt->execute([$assignmentId, $stationId]);
  $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$assignment) {
    out(404, [
      "ok" => false,
      "message" => "Assignment not found under your station."
    ]);
  }

  if ((int)$assignment["backup_requested"] !== 1) {
    out(409, [
      "ok" => false,
      "message" => "This assignment has no backup request."
    ]);
  }

  $pdo->beginTransaction();

  $update = $pdo->prepare("
    UPDATE responder_assignments
    SET backup_admin_response = ?,
        backup_response_notes = ?,
        backup_responded_by = ?,
        backup_responded_at = NOW()
    WHERE id = ?
      AND assigned_station_id = ?
  ");

  $update->execute([
    $response,
    $notes !== "" ? $notes : null,
    (int)$admin["id"],
    $assignmentId,
    $stationId
  ]);

  $notifTitle = $response === "approved"
    ? "Backup Request Approved"
    : "Backup Request Denied";

  $notifMessage = $response === "approved"
    ? "Station Admin approved your backup request."
    : "Station Admin denied your backup request.";

  if ($notes !== "") {
    $notifMessage .= " Feedback: " . $notes;
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
    $response === "approved" ? "BACKUP_APPROVED" : "BACKUP_DENIED",
    $notifTitle,
    $notifMessage,
    $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null
  ]);

  write_audit_log(
    $pdo,
    $admin,
    $response === "approved" ? "BACKUP_REQUEST_APPROVED" : "BACKUP_REQUEST_DENIED",
    "responder_assignment",
    $assignmentId,
    $response === "approved"
      ? "Station Admin approved a Police on Field backup request."
      : "Station Admin denied a Police on Field backup request.",
    [
      "module" => "dispatch_queue",
      "assignment_id" => $assignmentId,
      "incident_id" => $assignment["source_type"] === "incident" ? (int)$assignment["source_id"] : null,
      "panic_id" => $assignment["source_type"] === "panic" ? (int)$assignment["source_id"] : null,
      "target_user_id" => (int)$assignment["assigned_user_id"],
      "old_values" => [
        "backup_requested" => $assignment["backup_requested"],
        "backup_requested_at" => $assignment["backup_requested_at"],
        "backup_admin_response" => $assignment["backup_admin_response"],
        "backup_response_notes" => $assignment["backup_response_notes"],
        "backup_responded_by" => $assignment["backup_responded_by"],
        "backup_responded_at" => $assignment["backup_responded_at"]
      ],
      "new_values" => [
        "backup_admin_response" => $response,
        "backup_response_notes" => $notes !== "" ? $notes : null,
        "backup_responded_by" => (int)$admin["id"]
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => $response === "approved"
      ? "Backup request approved successfully."
      : "Backup request denied successfully.",
    "assignment_id" => $assignmentId,
    "backup_admin_response" => $response
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