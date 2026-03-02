<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/verify.php"; // your existing verifier

header("Content-Type: application/json; charset=utf-8");

// verify.php should ideally set something like: $authUser (id, role, etc.)
// If your verify.php returns/echoes, keep it consistent with your project.

function one($conn, $sql) {
  $res = $conn->query($sql);
  if (!$res) return 0;
  $row = $res->fetch_assoc();
  return (int)($row["c"] ?? 0);
}

$totalReports = one($conn, "SELECT COUNT(*) c FROM incident_reports");

$pendingReports = one($conn, "
  SELECT COUNT(*) c
  FROM incident_reports
  WHERE status='PENDING'
");

$reviewedReports = one($conn, "
  SELECT COUNT(*) c
  FROM incident_reports
  WHERE status='REVIEWED'
");

$resolvedReports = one($conn, "
  SELECT COUNT(*) c
  FROM incident_reports
  WHERE status='RESOLVED'
");

$rejectedReports = one($conn, "
  SELECT COUNT(*) c
  FROM incident_reports
  WHERE status='REJECTED'
");

$panicNew = one($conn, "
  SELECT COUNT(*) c
  FROM panic_requests
  WHERE status='new'
");

$panicAck = one($conn, "
  SELECT COUNT(*) c
  FROM panic_requests
  WHERE status='ack'
");

$panicResolved = one($conn, "
  SELECT COUNT(*) c
  FROM panic_requests
  WHERE status='resolved'
");

$thisWeekReports = one($conn, "
  SELECT COUNT(*) c
  FROM incident_reports
  WHERE YEARWEEK(created_at, 1) = YEARWEEK(UTC_DATE(), 1)
");

echo json_encode([
  "totalReports" => $totalReports,
  "thisWeekReports" => $thisWeekReports,
  "reportsByStatus" => [
    "PENDING" => $pendingReports,
    "REVIEWED" => $reviewedReports,
    "RESOLVED" => $resolvedReports,
    "REJECTED" => $rejectedReports
  ],
  "panicByStatus" => [
    "new" => $panicNew,
    "ack" => $panicAck,
    "resolved" => $panicResolved
  ]
]);