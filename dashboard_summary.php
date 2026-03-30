<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function build_scope_clause(string $column, string $role, ?string $stationProvince, array &$params, string $paramName = ":station_province"): string {
  if ($role === "admin") {
    $params[$paramName] = $stationProvince;
    return " AND LOWER(TRIM({$column})) = LOWER(TRIM({$paramName})) ";
  }
  return "";
}

function one(PDO $pdo, string $sql, array $params = []): int {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
  }
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return (int)($row["c"] ?? 0);
}

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$paramsReports = [];
$incidentScope = build_scope_clause("province", $role, $stationProvince, $paramsReports);

$paramsPanic = [];
$panicScope = build_scope_clause("province", $role, $stationProvince, $paramsPanic);

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_province" => $role === "admin" ? $stationProvince : null,
    "is_global" => $role === "super_admin"
  ],
  "cards" => [
    "totalReports" => one(
      $pdo,
      "SELECT COUNT(*) c FROM incident_reports WHERE 1=1 {$incidentScope}",
      $paramsReports
    ),

    "reportsThisWeek" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE YEARWEEK(created_at, 1) = YEARWEEK(UTC_DATE(), 1)
       {$incidentScope}",
      $paramsReports
    ),

    "pendingVerification" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'PENDING'
       {$incidentScope}",
      $paramsReports
    ),

    "verifiedIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'VERIFIED'
       {$incidentScope}",
      $paramsReports
    ),

    "falseReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'FALSE_REPORT'
       {$incidentScope}",
      $paramsReports
    ),

    "duplicateReports" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'DUPLICATE'
       {$incidentScope}",
      $paramsReports
    ),

    "openCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE case_status = 'OPEN'
       {$incidentScope}",
      $paramsReports
    ),

    "resolvedCases" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE (incident_phase = 'RESOLVED'
          OR case_status IN ('CLOSED','SOLVED','CLEARED'))
       {$incidentScope}",
      $paramsReports
    ),

    "riskIncidents" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM incident_reports
       WHERE verification_status = 'VERIFIED'
         AND risk_status = 'RISK'
       {$incidentScope}",
      $paramsReports
    ),

    "panicNew" => one(
      $pdo,
      "SELECT COUNT(*) c
       FROM panic_requests
       WHERE status = 'new'
       {$panicScope}",
      $paramsPanic
    ),

    "activeHotspots" => one(
      $pdo,
      "SELECT COUNT(DISTINCT hotspot_id) c
       FROM incident_reports
       WHERE verification_status = 'VERIFIED'
         AND incident_phase <> 'REJECTED'
         AND hotspot_id IS NOT NULL
       {$incidentScope}",
      $paramsReports
    )
  ]
]);