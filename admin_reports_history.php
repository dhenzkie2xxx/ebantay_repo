<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing id"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT
    h.id,
    h.old_phase,
    h.new_phase,
    h.old_case_status,
    h.new_case_status,
    h.old_verification_status,
    h.new_verification_status,
    h.remarks,
    h.changed_at,
    u.firstname,
    u.lastname,
    u.email
  FROM incident_status_history h
  LEFT JOIN users u ON u.id = h.changed_by
  WHERE h.incident_id = ?
  ORDER BY h.changed_at DESC, h.id DESC
");
$stmt->execute([$id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "history" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "old_phase" => $r["old_phase"],
      "new_phase" => $r["new_phase"],
      "old_case_status" => $r["old_case_status"],
      "new_case_status" => $r["new_case_status"],
      "old_verification_status" => $r["old_verification_status"],
      "new_verification_status" => $r["new_verification_status"],
      "remarks" => $r["remarks"],
      "changed_at" => $r["changed_at"],
      "changed_by" => trim(($r["firstname"] ?? "") . " " . ($r["lastname"] ?? "")) ?: ($r["email"] ?? "Unknown")
    ];
  }, $rows)
]);