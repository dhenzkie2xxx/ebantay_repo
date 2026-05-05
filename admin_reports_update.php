<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
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

$data = json_decode(file_get_contents("php://input"), true);

$id = (int)($data["id"] ?? 0);
$status = strtolower(trim((string)($data["status"] ?? "")));

$allowed = ["pending", "verified", "rejected"];

if ($id <= 0 || !in_array($status, $allowed, true)) {
  out(400, ["ok" => false, "message" => "Invalid payload"]);
}

$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;
$adminId = (int)($AUTH_USER["id"] ?? 0);

try {
  $pdo->beginTransaction();

  $oldStmt = $pdo->prepare("
    SELECT
      ir.id,
      ir.user_id,
      ir.verification_status,
      ir.assigned_station_id,
      ir.incident_phase
    FROM incident_reports ir
    WHERE ir.id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$id]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    $pdo->rollBack();
    out(404, ["ok" => false, "message" => "Incident not found"]);
  }

  if (
    $role === "admin" &&
    (int)($old["assigned_station_id"] ?? 0) !== $stationId
  ) {
    $pdo->rollBack();
    out(403, [
      "ok" => false,
      "message" => "You cannot update incidents outside your station."
    ]);
  }

  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET verification_status = ?,
        reviewed_by = ?,
        reviewed_at = NOW()
    WHERE id = ?
  ");

  $stmt->execute([
    $status,
    $adminId,
    $id
  ]);

  write_audit_log(
    $pdo,
    $AUTH_USER,
    $status === "verified"
      ? "INCIDENT_VERIFIED"
      : ($status === "rejected" ? "INCIDENT_REJECTED" : "INCIDENT_STATUS_UPDATED"),
    "incident_report",
    $id,
    "Station Admin updated incident verification status.",
    [
      "module" => "incident_reports",
      "incident_id" => $id,
      "target_user_id" => (int)$old["user_id"],
      "old_values" => [
        "verification_status" => $old["verification_status"],
        "incident_phase" => $old["incident_phase"],
        "assigned_station_id" => $old["assigned_station_id"]
      ],
      "new_values" => [
        "verification_status" => $status,
        "reviewed_by" => $adminId
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Incident updated successfully"
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