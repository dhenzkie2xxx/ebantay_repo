<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/user_flag_helpers.php";
require_once __DIR__ . "/hotspot_lib.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

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
  out(400, [
    "ok" => false,
    "message" => "Invalid payload"
  ]);
}

$adminId = (int)($AUTH_USER["id"] ?? 0);
$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;
$now = gmdate("Y-m-d H:i:s");

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
      id,
      title,
      reporter_user_id,
      verification_status,
      incident_phase,
      case_status,
      reviewed_at,
      province,
      assigned_station_id,
      duplicate_of_id
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    out(404, [
      "ok" => false,
      "message" => "Incident not found"
    ]);
  }

  if (
    $role === "admin" &&
    (int)($old["assigned_station_id"] ?? 0) !== $stationId
  ) {
    $pdo->rollBack();
    out(403, [
      "ok" => false,
      "message" => "You are not allowed to update incidents outside your station assignment."
    ]);
  }

  $duplicateOfId = null;
  $duplicateDistanceM = null;
  $duplicateSimilarity = null;
  $duplicateTimeDiffSec = null;

  if ($verificationStatus === "DUPLICATE") {
    $basisStmt = $pdo->prepare("
      SELECT
        id,
        duplicate_of_id,
        duplicate_distance_m,
        duplicate_similarity,
        duplicate_time_diff_sec
      FROM incident_reports
      WHERE id <> ?
        AND incident_type = (
          SELECT incident_type FROM incident_reports WHERE id = ? LIMIT 1
        )
        AND verification_status IN ('PENDING', 'VERIFIED', 'DUPLICATE')
        AND created_at >= DATE_SUB(
          (SELECT created_at FROM incident_reports WHERE id = ? LIMIT 1),
          INTERVAL 12 HOUR
        )
        AND created_at <= (
          SELECT created_at FROM incident_reports WHERE id = ? LIMIT 1
        )
      ORDER BY created_at ASC
      LIMIT 1
    ");

    $basisStmt->execute([$id, $id, $id, $id]);
    $basis = $basisStmt->fetch(PDO::FETCH_ASSOC);

    if ($basis) {
      $basisParentId = (int)($basis["duplicate_of_id"] ?? 0);
      $duplicateOfId = $basisParentId > 0 ? $basisParentId : (int)$basis["id"];
    }
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
    END,
    duplicate_of_id = CASE
      WHEN :verification_status_duplicate = 1 THEN :duplicate_of_id
      ELSE NULL
    END,
    duplicate_distance_m = CASE
      WHEN :verification_status_duplicate = 1 THEN duplicate_distance_m
      ELSE NULL
    END,
    duplicate_similarity = CASE
      WHEN :verification_status_duplicate = 1 THEN duplicate_similarity
      ELSE NULL
    END,
    duplicate_time_diff_sec = CASE
      WHEN :verification_status_duplicate = 1 THEN duplicate_time_diff_sec
      ELSE NULL
    END
    WHERE id = :id
  ");

  $stmt->execute([
    ":verification_status" => $verificationStatus,
    ":incident_phase" => $incidentPhase,
    ":case_status" => $caseStatus,
    ":admin_notes" => $notes,
    ":reviewed_by" => $adminId,
    ":set_reviewed_at" => ($verificationStatus === "VERIFIED" && empty($old["reviewed_at"])) ? 1 : 0,
    ":reviewed_at" => $now,
    ":set_resolved_at" => ($incidentPhase === "RESOLVED") ? 1 : 0,
    ":resolved_at" => $now,
    ":verification_status_duplicate" => $verificationStatus === "DUPLICATE" ? 1 : 0,
    ":duplicate_of_id" => $duplicateOfId,
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

  write_audit_log(
  $pdo,
  $AUTH_USER,
  $verificationStatus === "VERIFIED"
    ? "INCIDENT_VERIFIED"
    : ($verificationStatus === "FALSE_REPORT"
      ? "INCIDENT_FALSE_REPORT_MARKED"
      : ($incidentPhase === "REJECTED"
        ? "INCIDENT_REJECTED"
        : ($incidentPhase === "RESOLVED"
          ? "INCIDENT_RESOLVED"
          : "INCIDENT_STATUS_UPDATED"))),
  "incident_report",
  $id,
  "Station Admin updated incident report status.",
  [
    "module" => "incident_reports",
    "incident_id" => $id,
    "target_user_id" => $reporterUserId > 0 ? $reporterUserId : null,
    "old_values" => [
      "verification_status" => $old["verification_status"],
      "incident_phase" => $old["incident_phase"],
      "case_status" => $old["case_status"]
    ],
    "new_values" => [
      "verification_status" => $verificationStatus,
      "incident_phase" => $incidentPhase,
      "case_status" => $caseStatus,
      "admin_notes" => $notes !== "" ? $notes : null,
      "reviewed_by" => $adminId
    ]
  ]
);

  $oldVerification = strtoupper((string)($old["verification_status"] ?? ""));

  if (
    $verificationStatus === "FALSE_REPORT" &&
    $oldVerification !== "FALSE_REPORT" &&
    $reporterUserId > 0
  ) {
    flag_user_after_false_report(
      $pdo,
      $reporterUserId,
      $id,
      $adminId
    );
  }

  if (
    $reporterUserId > 0 &&
    (
      $oldVerification !== $verificationStatus ||
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

  /*
    Auto hotspot detection:
    Trigger when an incident becomes VERIFIED and is in a valid historical/active phase.
  */
  $hotspotResult = null;

  if (
    $verificationStatus === "VERIFIED" &&
    in_array($incidentPhase, ["RESOLVED", "UNDER_INVESTIGATION", "BLOTTERED", "FILED_IN_COURT"], true)
  ) {
    $hotspotResult = recalc_hotspots_after_incident_save($pdo, $id);
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Incident updated successfully",
    "hotspot" => $hotspotResult,
    "scope" => [
      "role" => $role,
      "station_id" => $role === "admin" ? $stationId : null,
      "assigned_station_id" => $old["assigned_station_id"] !== null ? (int)$old["assigned_station_id"] : null,
      "incident_province" => $old["province"] ?? null
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