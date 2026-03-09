<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$id = (int)($data["id"] ?? 0);
$verificationStatus = strtoupper(trim($data["verification_status"] ?? ""));
$incidentPhase = strtoupper(trim($data["incident_phase"] ?? ""));
$caseStatus = strtoupper(trim($data["case_status"] ?? ""));
$notes = trim($data["admin_notes"] ?? "");

$allowedVerification = ["PENDING","VERIFIED","FALSE_REPORT","DUPLICATE"];
$allowedPhase = ["REPORTED","UNDER_VERIFICATION","BLOTTERED","UNDER_INVESTIGATION","FILED_IN_COURT","RESOLVED","REJECTED"];
$allowedCase = ["OPEN","CLEARED","SOLVED","CLOSED","UNFOUNDED"];

if (
  $id <= 0 ||
  !in_array($verificationStatus, $allowedVerification, true) ||
  !in_array($incidentPhase, $allowedPhase, true) ||
  !in_array($caseStatus, $allowedCase, true)
) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Invalid payload"]);
  exit;
}

$adminId = (int)($AUTH_USER["id"] ?? 0);
$now = gmdate("Y-m-d H:i:s");

$oldStmt = $pdo->prepare("
  SELECT id, lat, lng, verification_status, incident_phase, case_status
  FROM incident_reports
  WHERE id = ?
  LIMIT 1
");
$oldStmt->execute([$id]);
$old = $oldStmt->fetch(PDO::FETCH_ASSOC);

if (!$old) {
  http_response_code(404);
  echo json_encode(["ok"=>false,"message"=>"Incident not found"]);
  exit;
}

$reviewedAt = $old["verification_status"] === "PENDING" && $verificationStatus !== "PENDING"
  ? $now
  : null;

$resolvedAt = ($incidentPhase === "RESOLVED" || $caseStatus === "CLOSED")
  ? $now
  : null;

try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET verification_status = :verification_status,
        incident_phase = :incident_phase,
        case_status = :case_status,
        admin_notes = :admin_notes,
        reviewed_by = :reviewed_by,
        reviewed_at = COALESCE(reviewed_at, :reviewed_at),
        resolved_at = CASE
          WHEN :resolved_at IS NOT NULL THEN :resolved_at
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
    ":reviewed_at" => $reviewedAt,
    ":resolved_at" => $resolvedAt,
    ":id" => $id
  ]);

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
  $hist->execute([
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

  // Refresh hotspot link for the current incident
  hotspot_refresh_incident_link($pdo, $id);

  // Refresh nearby incidents too, so cluster linkage stays consistent
  if ($old["lat"] !== null && $old["lng"] !== null) {
    hotspot_refresh_nearby_links($pdo, (float)$old["lat"], (float)$old["lng"], 500);
  }

  // Disable hotspots that no longer have any verified linked incidents
  hotspot_deactivate_orphan_hotspots($pdo);

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