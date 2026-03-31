<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$scope = admin_scope_from_auth($pdo, $AUTH_USER);

function one(PDO $pdo, string $sql, array $params = []): int {
  $q = $pdo->prepare($sql);
  $q->execute($params);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  return (int)($row["c"] ?? 0);
}

$params = [];
$incidentWhere = " WHERE 1=1 ";
$panicWhere = " WHERE 1=1 ";
$hotspotWhere = " WHERE 1=1 ";

$incidentWhere .= scope_where_clause("province", $scope, $params, ":incident_province");
$panicWhere .= scope_where_clause("province", $scope, $params, ":panic_province");
$hotspotWhere .= scope_where_clause("province", $scope, $params, ":hotspot_province");

echo json_encode([
  "ok" => true,
  "scope" => $scope,
  "cards" => [
    "totalReports" => one(
      $pdo,
      "SELECT COUNT(*) c FROM incident_reports $incidentWhere",
      $params
    ),

    "reportsThisWeek" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND YEARWEEK(created_at, 1) = YEARWEEK(UTC_DATE(), 1)",
      $params
    ),

    "pendingVerification" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND verification_status = 'PENDING'",
      $params
    ),

    "verifiedIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND verification_status = 'VERIFIED'",
      $params
    ),

    "falseReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND verification_status = 'FALSE_REPORT'",
      $params
    ),

    "duplicateReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND verification_status = 'DUPLICATE'",
      $params
    ),

    "openCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND case_status = 'OPEN'",
      $params
    ),

    "resolvedCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND (incident_phase = 'RESOLVED'
         OR case_status IN ('CLOSED','SOLVED','CLEARED'))",
      $params
    ),

    "riskIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       $incidentWhere
       AND verification_status = 'VERIFIED'
       AND risk_status = 'RISK'",
      $params
    ),

    "panicNew" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM panic_requests
       $panicWhere
       AND status = 'new'",
      $params
    ),

    "activeHotspots" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM crime_hotspots
       $hotspotWhere
       AND active = 1",
      $params
    )
  ]
]);