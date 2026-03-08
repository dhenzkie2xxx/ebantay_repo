<?php
require_once __DIR__ . "/require_admin.php";

$__t0 = microtime(true);
register_shutdown_function(function() use ($__t0) {
  error_log("END " . ($_SERVER["REQUEST_URI"] ?? "") . " took " . round((microtime(true)-$__t0)*1000) . "ms");
});

header("Content-Type: application/json; charset=UTF-8");

function one($pdo, $sql) {
  $q = $pdo->query($sql);
  $row = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
  return (int)($row["c"] ?? 0);
}

echo json_encode([
  "ok" => true,
  "cards" => [
    "totalReports" => one($pdo, "SELECT COUNT(*) c FROM incident_reports"),

    "reportsThisWeek" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE YEARWEEK(created_at, 1) = YEARWEEK(UTC_DATE(), 1)"
    ),

    "pendingVerification" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'PENDING'"
    ),

    "verifiedIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'VERIFIED'"
    ),

    "falseReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'FALSE_REPORT'"
    ),

    "duplicateReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'DUPLICATE'"
    ),

    "openCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE case_status = 'OPEN'"
    ),

    "resolvedCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE incident_phase = 'RESOLVED'
          OR case_status IN ('CLOSED','SOLVED','CLEARED')"
    ),

    "riskIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'VERIFIED'
         AND risk_status = 'RISK'"
    ),

    "panicNew" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM panic_requests
       WHERE status = 'new'"
    ),

    "activeHotspots" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM crime_hotspots
       WHERE active = 1"
    )
  ]
]);