<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/user_flag_helpers.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data["id"] ?? 0);
$status = strtolower(trim((string)($data["status"] ?? "")));

$allowed = ["new", "ack", "resolved", "false_alarm"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  out(400, ["ok" => false, "message" => "Invalid payload"]);
}

$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;
$adminId = (int)($AUTH_USER["id"] ?? 0);

if ($role === "admin" && $stationId <= 0) {
  out(403, [
    "ok" => false,
    "message" => "Admin station is not configured."
  ]);
}

function queue_user_notification(
  PDO $pdo,
  int $userId,
  string $type,
  string $title,
  string $message,
  ?int $hotspotId = null,
  ?int $incidentId = null,
  string $severity = "MEDIUM"
): void {
  $severity = strtoupper(trim($severity));
  if (!in_array($severity, ["LOW", "MEDIUM", "HIGH"], true)) {
    $severity = "MEDIUM";
  }

  $stmt = $pdo->prepare("
    INSERT INTO notification_alerts
    (
      user_id,
      type,
      title,
      message,
      hotspot_id,
      incident_id,
      severity,
      is_read,
      created_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())
  ");

  $stmt->execute([
    $userId,
    $type,
    $title,
    $message,
    $hotspotId,
    $incidentId,
    $severity
  ]);
}

try {
  $pdo->beginTransaction();

  $oldStmt = $pdo->prepare("
    SELECT
      p.id,
      p.user_id,
      p.level,
      p.status,
      p.province,
      p.assigned_station_id
    FROM panic_requests p
    WHERE p.id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    out(404, ["ok" => false, "message" => "Panic request not found"]);
  }

  if (
    $role === "admin" &&
    (int)($old["assigned_station_id"] ?? 0) !== $stationId
  ) {
    $pdo->rollBack();
    out(403, [
      "ok" => false,
      "message" => "You are not allowed to update panic requests outside your station assignment."
    ]);
  }

  $stmt = $pdo->prepare("
    UPDATE panic_requests
    SET status = ?
    WHERE id = ?
  ");
  $stmt->execute([$status, $id]);

  $oldStatus = strtolower((string)($old["status"] ?? ""));
  $panicUserId = (int)($old["user_id"] ?? 0);

  if ($status === "false_alarm" && $oldStatus !== "false_alarm" && $panicUserId > 0) {
    flag_user_after_false_alarm(
      $pdo,
      $panicUserId,
      $id,
      $adminId
    );
  }

  if ($oldStatus !== $status && $panicUserId > 0) {
    $panicTitle = "Panic Request Update";
    $panicMessage = "Your panic request was updated to status: " . strtoupper($status) . ".";

    $severity = "HIGH";
    if ($status === "ack") $severity = "MEDIUM";
    if ($status === "resolved") $severity = "LOW";
    if ($status === "false_alarm") $severity = "HIGH";

    queue_user_notification(
      $pdo,
      $panicUserId,
      "PANIC_STATUS",
      $panicTitle,
      $panicMessage,
      null,
      null,
      $severity
    );
  }

  write_audit_log(
    $pdo,
    $AUTH_USER,
    $status === "resolved"
      ? "PANIC_RESOLVED"
      : ($status === "false_alarm" ? "PANIC_FALSE_ALARM_MARKED" : "PANIC_STATUS_UPDATED"),
    "panic_request",
    $id,
    "Station Admin updated a panic request status.",
    [
      "module" => "panic_requests",
      "panic_id" => $id,
      "target_user_id" => $panicUserId > 0 ? $panicUserId : null,
      "old_values" => [
        "status" => $old["status"],
        "level" => $old["level"],
        "assigned_station_id" => $old["assigned_station_id"]
      ],
      "new_values" => [
        "status" => $status
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Panic request updated successfully",
    "scope" => [
      "role" => $role,
      "station_id" => $role === "admin" ? $stationId : null,
      "assigned_station_id" => $old["assigned_station_id"] !== null ? (int)$old["assigned_station_id"] : null,
      "panic_province" => $old["province"] ?? null
    ]
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}