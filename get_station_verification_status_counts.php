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
    SELECT
      verification_status,
      COUNT(*) AS total
    FROM police_stations
    WHERE verification_status IN (
      'pending',
      'under_review',
      'resubmission_required',
      'draft'
    )
    GROUP BY verification_status
  ");

  $counts = [
    "pending" => 0,
    "under_review" => 0,
    "resubmission_required" => 0,
    "draft" => 0
  ];

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = $row["verification_status"];
    if (array_key_exists($status, $counts)) {
      $counts[$status] = (int)$row["total"];
    }
  }

  auth_out(200, [
    "ok" => true,
    "counts" => $counts,
    "total" =>
      $counts["pending"] +
      $counts["under_review"] +
      $counts["resubmission_required"] +
      $counts["draft"]
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Failed to load station verification status counts."
  ]);
}