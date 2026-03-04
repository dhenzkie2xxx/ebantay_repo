<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$id = (int)($data["id"] ?? 0);
$status = strtoupper(trim($data["status"] ?? ""));
$notes = trim($data["admin_notes"] ?? "");

$allowed = ["PENDING","REVIEWED","RESOLVED","REJECTED"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Invalid payload"]);
  exit;
}

$now = gmdate("Y-m-d H:i:s");
$adminId = (int)($AUTH_USER["id"] ?? 0);

$reviewedAt = null;
$resolvedAt = null;

if ($status === "REVIEWED") $reviewedAt = $now;
if ($status === "RESOLVED") $resolvedAt = $now;

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

echo json_encode(["ok"=>true, "message"=>"Updated"]);