<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

$incidentId = (int)($data["incident_id"] ?? 0);
$blotterEntryNumber = trim((string)($data["blotter_entry_number"] ?? ""));
$irfEntryNumber = trim((string)($data["irf_entry_number"] ?? ""));
$notes = trim((string)($data["admin_notes"] ?? ""));

if ($incidentId <= 0 || $blotterEntryNumber === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing required fields"]);
  exit;
}

$adminId = (int)($AUTH_USER["id"] ?? 0);

try {
  $pdo->beginTransaction();

  $sel = $pdo->prepare("
    SELECT incident_phase, case_status, verification_status
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $sel->execute([$incidentId]);
  $old = $sel->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    throw new Exception("Incident not found");
  }

  $upd = $pdo->prepare("
    UPDATE incident_reports
    SET
      blotter_entry_number = ?,
      irf_entry_number = ?,
      incident_phase = 'BLOTTERED',
      case_status = 'OPEN',
      reviewed_by = ?,
      reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()),
      admin_notes = CASE
        WHEN ? <> '' THEN ?
        ELSE admin_notes
      END
    WHERE id = ?
  ");
  $upd->execute([
    $blotterEntryNumber,
    $irfEntryNumber !== "" ? $irfEntryNumber : null,
    $adminId,
    $notes,
    $notes,
    $incidentId
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
    $incidentId,
    $old["incident_phase"],
    "BLOTTERED",
    $old["case_status"],
    "OPEN",
    $old["verification_status"],
    $old["verification_status"],
    "Blotter filed. " . $notes,
    $adminId
  ]);

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "message" => "Blotter filed successfully"
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