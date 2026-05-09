<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  auth_out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

try {
  $stmt = $pdo->query("
    SELECT COUNT(*) AS total
    FROM police_stations
    WHERE verification_status IN (
      'pending',
      'under_review',
      'draft',
      'resubmission_required'
    )
  ");

  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  auth_out(200, [
    "ok" => true,
    "count" => (int)($row["total"] ?? 0)
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Failed to load badge count."
  ]);
}