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

function is_valid_date_ymd($date) {
  if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map("intval", explode("-", $date));
  return checkdate($m, $d, $y);
}

try {
  /*
  |--------------------------------------------------------------------------
  | FILTER MODE
  |--------------------------------------------------------------------------
  | Dashboard:
  |   no mode sent = original behavior
  |
  | DataAnalytics:
  |   ?mode=year&year=2026
  |   ?mode=custom&from=2026-01-01&to=2026-05-06
  |--------------------------------------------------------------------------
  */

  $mode = $_GET["mode"] ?? "";
  $year = $_GET["year"] ?? "";
  $from = $_GET["from"] ?? "";
  $to = $_GET["to"] ?? "";

  $filterMode = "default";
  $periodLabel = "Default Dashboard Scope";

  $incidentParams = [];
  $incidentWhere = " WHERE 1=1 ";

  if ($mode === "year" && preg_match('/^\d{4}$/', (string)$year)) {
    $filterMode = "year";
    $year = (int)$year;

    $incidentWhere .= "
      AND COALESCE(date_reported, created_at) >= :incident_year_start
      AND COALESCE(date_reported, created_at) < :incident_year_end
    ";

    $incidentParams[":incident_year_start"] = $year . "-01-01 00:00:00";
    $incidentParams[":incident_year_end"] = ($year + 1) . "-01-01 00:00:00";

    $periodLabel = "Year " . $year;

  } elseif ($mode === "custom" && is_valid_date_ymd($from) && is_valid_date_ymd($to)) {
    if (strtotime($from) > strtotime($to)) {
      http_response_code(400);
      echo json_encode([
        "ok" => false,
        "message" => "Invalid date range. From date must not be later than To date."
      ]);
      exit;
    }

    $filterMode = "custom";

    $incidentWhere .= "
      AND COALESCE(date_reported, created_at) >= :incident_date_from
      AND COALESCE(date_reported, created_at) < DATE_ADD(:incident_date_to, INTERVAL 1 DAY)
    ";

    $incidentParams[":incident_date_from"] = $from . " 00:00:00";
    $incidentParams[":incident_date_to"] = $to . " 00:00:00";

    $periodLabel = $from . " to " . $to;
  }

  $incidentWhere .= scope_where_clause("province", $scope, $incidentParams, ":incident_province");
  $incidentWhere .= scope_city_where_clause("city_municipality", $scope, $incidentParams, ":incident_city");

  /*
    Panic and active hotspots remain operational/current.
    Do not date-filter them here unless you specifically want analytics-only panic cards later.
  */

  $panicParams = [];
  $panicWhere = " WHERE 1=1 ";
  $panicWhere .= scope_where_clause("province", $scope, $panicParams, ":panic_province");
  $panicWhere .= scope_city_where_clause("city_municipality", $scope, $panicParams, ":panic_city");

  $hotspotParams = [];
  $hotspotWhere = " WHERE 1=1 ";
  $hotspotWhere .= scope_where_clause("province", $scope, $hotspotParams, ":hotspot_province");
  $hotspotWhere .= scope_city_where_clause("city_municipality", $scope, $hotspotParams, ":hotspot_city");

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "filters" => [
      "mode" => $filterMode,
      "year" => $filterMode === "year" ? (int)$year : null,
      "from" => $filterMode === "custom" ? $from : null,
      "to" => $filterMode === "custom" ? $to : null,
      "period_label" => $periodLabel
    ],
    "cards" => [
      "totalReports" => one(
        $pdo,
        "SELECT COUNT(*) c FROM incident_reports $incidentWhere",
        $incidentParams
      ),

      "reportsThisWeek" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND YEARWEEK(created_at, 1) = YEARWEEK(UTC_DATE(), 1)",
        $incidentParams
      ),

      "pendingVerification" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND verification_status = 'PENDING'",
        $incidentParams
      ),

      "verifiedIncidents" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND verification_status = 'VERIFIED'",
        $incidentParams
      ),

      "falseReports" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND verification_status = 'FALSE_REPORT'",
        $incidentParams
      ),

      "duplicateReports" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND verification_status = 'DUPLICATE'",
        $incidentParams
      ),

      "openCases" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND case_status = 'OPEN'",
        $incidentParams
      ),

      "resolvedCases" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND (incident_phase = 'RESOLVED'
           OR case_status IN ('CLOSED','SOLVED','CLEARED'))",
        $incidentParams
      ),

      "riskIncidents" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM incident_reports
         $incidentWhere
         AND verification_status = 'VERIFIED'
         AND risk_status = 'RISK'",
        $incidentParams
      ),

      "panicNew" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM panic_requests
         $panicWhere
         AND status = 'new'",
        $panicParams
      ),

      "activeHotspots" => one(
        $pdo,
        "SELECT COUNT(*) c
         FROM crime_hotspots
         $hotspotWhere
         AND active = 1",
        $hotspotParams
      )
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage(),
    "file" => basename(__FILE__),
    "line" => $e->getLine()
  ]);
}