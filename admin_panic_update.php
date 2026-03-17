<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data["id"] ?? 0);
$status = strtolower(trim($data["status"] ?? ""));

$allowed = ["new", "ack", "resolved"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid payload"]);
  exit;
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
    SELECT id, user_id, level, status
    FROM panic_requests
    WHERE id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Panic request not found"]);
    exit;
  }

  $stmt = $pdo->prepare("
    UPDATE panic_requests
    SET status = ?
    WHERE id = ?
  ");
  $stmt->execute([$status, $id]);

  $oldStatus = strtolower((string)($old["status"] ?? ""));
  $userId = (int)($old["user_id"] ?? 0);

  if ($userId > 0 && $oldStatus !== $status) {
    $panicLevel = strtoupper(trim((string)($old["level"] ?? "ALERT")));
    $title = "Panic Request Update";
    $message = "Your panic request ({$panicLevel}) is now marked as " . strtoupper($status) . ".";

    $severity = "HIGH";
    if ($status === "resolved") $severity = "LOW";
    if ($status === "ack") $severity = "MEDIUM";

    queue_user_notification(
      $pdo,
      $userId,
      "PANIC_STATUS",
      $title,
      $message,
      null,
      null,
      $severity
    );
  }

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Updated"
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}