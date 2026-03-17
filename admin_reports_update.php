<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$id = (int)($data["id"] ?? 0);
$status = strtoupper(trim($data["status"] ?? ""));
$notes = trim($data["admin_notes"] ?? "");

$allowed = ["PENDING", "REVIEWED", "RESOLVED", "REJECTED"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid payload"]);
  exit;
}

$now = gmdate("Y-m-d H:i:s");
$adminId = (int)($AUTH_USER["id"] ?? 0);

$reviewedAt = null;
$resolvedAt = null;

if ($status === "REVIEWED") $reviewedAt = $now;
if ($status === "RESOLVED") $resolvedAt = $now;

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
    SELECT id, title, reporter_user_id, status
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(["ok" => false, "message" => "Incident not found"]);
    exit;
  }

  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET status = :status,
        admin_notes = :notes,
        reviewed_by = CASE WHEN :setReviewed = 1 THEN :adminId ELSE reviewed_by END,
        reviewed_at = CASE WHEN :setReviewed = 1 THEN :reviewedAt ELSE reviewed_at END,
        resolved_at = CASE WHEN :setResolved = 1 THEN :resolvedAt ELSE resolved_at END
    WHERE id = :id
  ");
  $stmt->execute([
    ":status" => $status,
    ":notes" => $notes,
    ":setReviewed" => ($status === "REVIEWED") ? 1 : 0,
    ":setResolved" => ($status === "RESOLVED") ? 1 : 0,
    ":adminId" => $adminId,
    ":reviewedAt" => $reviewedAt,
    ":resolvedAt" => $resolvedAt,
    ":id" => $id
  ]);

  $oldStatus = strtoupper((string)($old["status"] ?? ""));
  $reporterUserId = (int)($old["reporter_user_id"] ?? 0);

  if ($reporterUserId > 0 && $oldStatus !== $status) {
    $title = "Incident Report Update";
    $incidentTitle = trim((string)($old["title"] ?? ""));
    if ($incidentTitle === "") $incidentTitle = "Untitled Incident";

    $message = "Your reported incident \"" . $incidentTitle . "\" is now marked as {$status}.";
    if ($notes !== "") {
      $message .= " Admin note: " . $notes;
    }

    $severity = "MEDIUM";
    if ($status === "RESOLVED") $severity = "LOW";
    if ($status === "REJECTED") $severity = "HIGH";

    queue_user_notification(
      $pdo,
      $reporterUserId,
      "INCIDENT_STATUS",
      $title,
      $message,
      null,
      (int)$old["id"],
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