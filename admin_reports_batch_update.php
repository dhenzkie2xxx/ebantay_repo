<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$ids = $data["ids"] ?? [];
$status = strtoupper(trim((string)($data["status"] ?? "")));
$notes = trim((string)($data["admin_notes"] ?? ""));

$allowed = ["PENDING", "REVIEWED", "RESOLVED", "REJECTED"];

if (!is_array($ids) || count($ids) === 0 || !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "Invalid payload"
  ]);
  exit;
}

// sanitize ids
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
if (count($ids) === 0) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "No valid report IDs"
  ]);
  exit;
}

$now = gmdate("Y-m-d H:i:s");
$adminId = (int)($AUTH_USER["id"] ?? 0);

$reviewedAt = null;
$resolvedAt = null;

if ($status === "REVIEWED") $reviewedAt = $now;
if ($status === "RESOLVED") $resolvedAt = $now;

try {
  $placeholders = implode(",", array_fill(0, count($ids), "?"));

  $sql = "
    UPDATE incident_reports
    SET status = ?,
        admin_notes = CASE
          WHEN ? <> '' THEN ?
          ELSE admin_notes
        END,
        reviewed_by = CASE
          WHEN ? = 'REVIEWED' OR ? = 'RESOLVED' THEN ?
          ELSE reviewed_by
        END,
        reviewed_at = CASE
          WHEN ? = 'REVIEWED' THEN ?
          WHEN ? = 'RESOLVED' THEN COALESCE(reviewed_at, ?)
          ELSE reviewed_at
        END,
        resolved_at = CASE
          WHEN ? = 'RESOLVED' THEN ?
          ELSE resolved_at
        END
    WHERE id IN ($placeholders)
  ";

  $params = [
    $status,
    $notes, $notes,
    $status, $status, $adminId,
    $status, $reviewedAt,
    $status, $now,
    $status, $resolvedAt
  ];

  foreach ($ids as $id) $params[] = $id;

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode([
    "ok" => true,
    "message" => "Batch update successful",
    "updated_count" => $stmt->rowCount()
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error"
  ]);
}