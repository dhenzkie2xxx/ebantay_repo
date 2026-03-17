<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$ids = $data["ids"] ?? [];
$verificationStatus = strtoupper(trim((string)($data["verification_status"] ?? "")));
$incidentPhase = strtoupper(trim((string)($data["incident_phase"] ?? "")));
$caseStatus = strtoupper(trim((string)($data["case_status"] ?? "")));
$notes = trim((string)($data["admin_notes"] ?? ""));

$allowedVerification = ["PENDING","VERIFIED","FALSE_REPORT","DUPLICATE"];
$allowedPhase = ["REPORTED","UNDER_VERIFICATION","BLOTTERED","UNDER_INVESTIGATION","FILED_IN_COURT","RESOLVED","REJECTED"];
$allowedCase = ["OPEN","CLEARED","SOLVED","CLOSED","UNFOUNDED"];

if (
  !is_array($ids) || count($ids) === 0 ||
  !in_array($verificationStatus, $allowedVerification, true) ||
  !in_array($incidentPhase, $allowedPhase, true) ||
  !in_array($caseStatus, $allowedCase, true)
) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid payload"]);
  exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
if (!$ids) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "No valid IDs"]);
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

  $ph = implode(",", array_fill(0, count($ids), "?"));

  $sel = $pdo->prepare("
    SELECT
      id,
      title,
      reporter_user_id,
      lat,
      lng,
      verification_status,
      incident_phase,
      case_status
    FROM incident_reports
    WHERE id IN ($ph)
  ");
  $sel->execute($ids);
  $oldRows = $sel->fetchAll(PDO::FETCH_ASSOC);

  if (!$oldRows) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode([
      "ok" => false,
      "message" => "No incidents found"
    ]);
    exit;
  }

  $upd = $pdo->prepare("
    UPDATE incident_reports
    SET verification_status = ?,
        incident_phase = ?,
        case_status = ?,
        admin_notes = CASE WHEN ? <> '' THEN ? ELSE admin_notes END,
        reviewed_by = ?,
        reviewed_at = CASE
          WHEN reviewed_at IS NULL AND ? <> 'PENDING' THEN ?
          ELSE reviewed_at
        END,
        resolved_at = CASE
          WHEN ? = 'RESOLVED' OR ? = 'CLOSED' THEN ?
          ELSE resolved_at
        END
    WHERE id = ?
  ");

  $hist = $pdo->prepare("
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

  foreach ($oldRows as $row) {
    $upd->execute([
      $verificationStatus,
      $incidentPhase,
      $caseStatus,
      $notes, $notes,
      $adminId,
      $verificationStatus, $now,
      $incidentPhase, $caseStatus, $now,
      $row["id"]
    ]);

    $hist->execute([
      $row["id"],
      $row["incident_phase"],
      $incidentPhase,
      $row["case_status"],
      $caseStatus,
      $row["verification_status"],
      $verificationStatus,
      $notes,
      $adminId
    ]);

    $reporterUserId = (int)($row["reporter_user_id"] ?? 0);

    $changed =
      strtoupper((string)$row["verification_status"]) !== $verificationStatus ||
      strtoupper((string)$row["incident_phase"]) !== $incidentPhase ||
      strtoupper((string)$row["case_status"]) !== $caseStatus;

    if ($reporterUserId > 0 && $changed) {
      $incidentTitle = trim((string)($row["title"] ?? ""));
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
        (int)$row["id"],
        $severity
      );
    }
  }

  foreach ($oldRows as $row) {
    hotspot_refresh_incident_link($pdo, (int)$row["id"]);

    if ($row["lat"] !== null && $row["lng"] !== null) {
      hotspot_refresh_nearby_links($pdo, (float)$row["lat"], (float)$row["lng"], 500);
    }
  }

  hotspot_deactivate_orphan_hotspots($pdo);

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Batch update successful",
    "updated_count" => count($oldRows)
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