<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";
require_once __DIR__ . "/user_flag_helpers.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$ids = $data["ids"] ?? [];
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
  !is_array($ids) ||
  count($ids) === 0 ||
  !in_array($verificationStatus, $allowedVerification, true) ||
  !in_array($incidentPhase, $allowedPhase, true) ||
  !in_array($caseStatus, $allowedCase, true)
) {
  out(400, ["ok" => false, "message" => "Invalid payload"]);
}

$ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($v) => $v > 0)));

if (!$ids) {
  out(400, ["ok" => false, "message" => "No valid IDs"]);
}

$adminId = (int)($AUTH_USER["id"] ?? 0);
$stationId = (int)($AUTH_USER["station_id"] ?? 0);
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
      case_status,
      admin_notes,
      reviewed_by,
      reviewed_at,
      resolved_at,
      assigned_station_id
    FROM incident_reports
    WHERE id IN ($ph)
      AND assigned_station_id = ?
  ");

  $sel->execute([...$ids, $stationId]);
  $oldRows = $sel->fetchAll(PDO::FETCH_ASSOC);

  if (!$oldRows) {
    $pdo->rollBack();
    out(404, [
      "ok" => false,
      "message" => "No incidents found under your station"
    ]);
  }

  $foundIds = array_map(fn($r) => (int)$r["id"], $oldRows);
  $phFound = implode(",", array_fill(0, count($foundIds), "?"));

  $upd = $pdo->prepare("
    UPDATE incident_reports
    SET
      verification_status = ?,
      incident_phase = ?,
      case_status = ?,
      admin_notes = ?,
      reviewed_by = ?,
      reviewed_at = CASE
        WHEN ? = 'VERIFIED' AND reviewed_at IS NULL THEN ?
        ELSE reviewed_at
      END,
      resolved_at = CASE
        WHEN ? = 'RESOLVED' THEN ?
        ELSE resolved_at
      END
    WHERE id IN ($phFound)
      AND assigned_station_id = ?
  ");

  $upd->execute([
    $verificationStatus,
    $incidentPhase,
    $caseStatus,
    $notes,
    $adminId,
    $verificationStatus,
    $now,
    $incidentPhase,
    $now,
    ...$foundIds,
    $stationId
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

  foreach ($oldRows as $old) {
    $incidentId = (int)$old["id"];
    $reporterUserId = (int)($old["reporter_user_id"] ?? 0);

    $historyStmt->execute([
      $incidentId,
      $old["incident_phase"],
      $incidentPhase,
      $old["case_status"],
      $caseStatus,
      $old["verification_status"],
      $verificationStatus,
      $notes,
      $adminId
    ]);

    if (
      $verificationStatus === "FALSE_REPORT" &&
      strtoupper((string)$old["verification_status"]) !== "FALSE_REPORT" &&
      $reporterUserId > 0
    ) {
      flag_user_after_false_report(
        $pdo,
        $reporterUserId,
        $incidentId,
        $adminId
      );
    }

    $changed =
      strtoupper((string)$old["verification_status"]) !== $verificationStatus ||
      strtoupper((string)$old["incident_phase"]) !== $incidentPhase ||
      strtoupper((string)$old["case_status"]) !== $caseStatus;

    if ($reporterUserId > 0 && $changed) {
      $incidentTitle = trim((string)($old["title"] ?? ""));
      if ($incidentTitle === "") {
        $incidentTitle = "Untitled Incident";
      }

      $title = "Incident Report Update";
      $message = "Your reported incident \"{$incidentTitle}\" was updated. Verification: {$verificationStatus}, Phase: {$incidentPhase}, Case: {$caseStatus}.";

      if ($notes !== "") {
        $message .= " Admin note: {$notes}";
      }

      $severity = "MEDIUM";
      if ($incidentPhase === "RESOLVED" || $caseStatus === "CLOSED") {
        $severity = "LOW";
      }
      if ($verificationStatus === "FALSE_REPORT" || $incidentPhase === "REJECTED") {
        $severity = "HIGH";
      }

      queue_user_notification(
        $pdo,
        $reporterUserId,
        "INCIDENT_STATUS",
        $title,
        $message,
        null,
        $incidentId,
        $severity
      );
    }

    hotspot_refresh_incident_link($pdo, $incidentId);

    $newValues = [
      "verification_status" => $verificationStatus,
      "incident_phase" => $incidentPhase,
      "case_status" => $caseStatus,
      "admin_notes" => $notes,
      "reviewed_by" => $adminId,
      "reviewed_at" => ($verificationStatus === "VERIFIED" && empty($old["reviewed_at"]))
        ? $now
        : $old["reviewed_at"],
      "resolved_at" => ($incidentPhase === "RESOLVED")
        ? $now
        : $old["resolved_at"]
    ];

    $auditAction = "INCIDENT_BATCH_UPDATED";

    if ($verificationStatus === "VERIFIED") {
      $auditAction = "INCIDENT_BATCH_VERIFIED";
    }

    if ($verificationStatus === "FALSE_REPORT") {
      $auditAction = "INCIDENT_BATCH_FALSE_REPORT_MARKED";
    }

    if ($verificationStatus === "DUPLICATE") {
      $auditAction = "INCIDENT_BATCH_DUPLICATE_MARKED";
    }

    if ($incidentPhase === "REJECTED") {
      $auditAction = "INCIDENT_BATCH_REJECTED";
    }

    if ($incidentPhase === "RESOLVED" || $caseStatus === "CLOSED") {
      $auditAction = "INCIDENT_BATCH_RESOLVED";
    }

    write_audit_log(
      $pdo,
      $AUTH_USER,
      $auditAction,
      "incident_report",
      $incidentId,
      "Station Admin batch-updated an incident report status.",
      [
        "module" => "incident_reports",
        "incident_id" => $incidentId,
        "target_user_id" => $reporterUserId > 0 ? $reporterUserId : null,
        "old_values" => $old,
        "new_values" => $newValues
      ]
    );
  }

  hotspot_deactivate_orphan_hotspots($pdo);

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Batch update successful",
    "updated_count" => count($oldRows),
    "incident_ids" => $foundIds
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}