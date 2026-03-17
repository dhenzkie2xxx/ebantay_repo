<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$id = (int)($data["id"] ?? 0);
$verificationStatus = strtoupper(trim((string)($data["verification_status"] ?? "")));
$incidentPhase = strtoupper(trim((string)($data["incident_phase"] ?? "")));
$caseStatus = strtoupper(trim((string)($data["case_status"] ?? "")));
$notes = trim((string)($data["admin_notes"] ?? ""));

$allowedVerification = ["PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
$allowedPhase = [
  "REPORTED",
  "UNDER_VERIFICATION",
  "BLOTTERED",
  "UNDER_INVESTIGATION",
  "FILED_IN_COURT",
  "RESOLVED",
  "REJECTED"
];
$allowedCase = ["OPEN", "CLEARED", "SOLVED", "CLOSED", "UNFOUNDED"];

if (
  $id <= 0 ||
  !in_array($verificationStatus, $allowedVerification, true) ||
  !in_array($incidentPhase, $allowedPhase, true) ||
  !in_array($caseStatus, $allowedCase, true)
) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "Invalid payload"
  ]);
  exit;
}

$adminId = (int)($AUTH_USER["id"] ?? 0);
$now = gmdate("Y-m-d H:i:s");

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
      id,
      title,
      reporter_user_id,
      verification_status,
      incident_phase,
      case_status
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode([
      "ok" => false,
      "message" => "Incident not found"
    ]);
    exit;
  }

  $reviewedAt = null;
  $resolvedAt = null;

  if ($verificationStatus === "VERIFIED" && empty($old["reviewed_at"])) {
    $reviewedAt = $now;
  }

  if ($incidentPhase === "RESOLVED") {
    $resolvedAt = $now;
  }

  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET
      verification_status = :verification_status,
      incident_phase = :incident_phase,
      case_status = :case_status,
      admin_notes = :admin_notes,
      reviewed_by = :reviewed_by,
      reviewed_at = CASE
        WHEN :set_reviewed_at = 1 THEN :reviewed_at
        ELSE reviewed_at
      END,
      resolved_at = CASE
        WHEN :set_resolved_at = 1 THEN :resolved_at
        ELSE resolved_at
      END
    WHERE id = :id
  ");

  $stmt->execute([
    ":verification_status" => $verificationStatus,
    ":incident_phase" => $incidentPhase,
    ":case_status" => $caseStatus,
    ":admin_notes" => $notes,
    ":reviewed_by" => $adminId,
    ":set_reviewed_at" => ($verificationStatus === "VERIFIED") ? 1 : 0,
    ":reviewed_at" => $now,
    ":set_resolved_at" => ($incidentPhase === "RESOLVED") ? 1 : 0,
    ":resolved_at" => $now,
    ":id" => $id
  ]);

  $historyStmt = $pdo->prepare("
    INSERT INTO incident_status_history
    (
      incident_id,
      old_phase,
      new_phase,
      old_case_status,
      new_case_status,
      old_verification_status,
      new_verification_status,
      remarks,
      changed_by
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $historyStmt->execute([
    $id,
    $old["incident_phase"],
    $incidentPhase,
    $old["case_status"],
    $caseStatus,
    $old["verification_status"],
    $verificationStatus,
    $notes,
    $adminId
  ]);

  $reporterUserId = (int)($old["reporter_user_id"] ?? 0);

  if (
    $reporterUserId > 0 &&
    (
      strtoupper((string)$old["verification_status"]) !== $verificationStatus ||
      strtoupper((string)$old["incident_phase"]) !== $incidentPhase ||
      strtoupper((string)$old["case_status"]) !== $caseStatus
    )
  ) {
    $incidentTitle = trim((string)($old["title"] ?? ""));
    if ($incidentTitle === "") $incidentTitle = "Untitled Incident";

    $title = "Incident Report Update";
    $message = "Your reported incident \"{$incidentTitle}\" was updated. Verification: {$verificationStatus}, Phase: {$incidentPhase}, Case: {$caseStatus}.";
    if ($notes !== "") {
      $message .= " Admin note: {$notes}";
    }

    $severity = "MEDIUM";
    if ($incidentPhase === "RESOLVED" || $caseStatus === "CLOSED") $severity = "LOW";
    if ($verificationStatus === "FALSE_REPORT" || $incidentPhase === "REJECTED") $severity = "HIGH";

    queue_user_notification(
      $pdo,
      $reporterUserId,
      "INCIDENT_STATUS",
      $title,
      $message,
      null,
      $id,
      $severity
    );
  }

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Incident updated successfully"
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