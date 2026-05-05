<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$scope = admin_scope_from_auth($pdo, $AUTH_USER);

function is_valid_date_ymd($date) {
  if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map("intval", explode("-", $date));
  return checkdate($m, $d, $y);
}

/*
|--------------------------------------------------------------------------
| FILTER MODE
|--------------------------------------------------------------------------
| Dashboard = no filter (all-time)
| DataAnalytics = year/custom
|--------------------------------------------------------------------------
*/

$mode = $_GET["mode"] ?? "";
$year = $_GET["year"] ?? "";
$from = $_GET["from"] ?? "";
$to = $_GET["to"] ?? "";

$where = " WHERE 1=1 ";
$params = [];

$filterMode = "default";
$periodLabel = "All Time";

/* -------- APPLY DATE FILTER -------- */

if ($mode === "year" && preg_match('/^\d{4}$/', (string)$year)) {
  $filterMode = "year";
  $year = (int)$year;

  $where .= "
    AND COALESCE(date_reported, created_at) >= :year_start
    AND COALESCE(date_reported, created_at) < :year_end
  ";

  $params[":year_start"] = $year . "-01-01 00:00:00";
  $params[":year_end"] = ($year + 1) . "-01-01 00:00:00";

  $periodLabel = "Year " . $year;

} elseif ($mode === "custom" && is_valid_date_ymd($from) && is_valid_date_ymd($to)) {

  if (strtotime($from) > strtotime($to)) {
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "message" => "Invalid date range."
    ]);
    exit;
  }

  $filterMode = "custom";

  $where .= "
    AND COALESCE(date_reported, created_at) >= :date_from
    AND COALESCE(date_reported, created_at) < DATE_ADD(:date_to, INTERVAL 1 DAY)
  ";

  $params[":date_from"] = $from . " 00:00:00";
  $params[":date_to"] = $to . " 00:00:00";

  $periodLabel = $from . " to " . $to;
}

/* -------- SCOPE FILTER -------- */

$where .= scope_where_clause("province", $scope, $params, ":province");
$where .= scope_city_where_clause("city_municipality", $scope, $params, ":city");

/* -------- ADMIN STATION FILTER -------- */

$role = strtolower(trim((string)($AUTH_USER["role"] ?? "")));
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;

if ($role === "admin") {
  if ($stationId <= 0) {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "message" => "Admin station is not configured."
    ]);
    exit;
  }

  $where .= " AND assigned_station_id = :station_id ";
  $params[":station_id"] = $stationId;
}

/* -------- STATUS COUNTS -------- */

$statuses = ["PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
$items = [];

$stmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM incident_reports
  $where
  AND verification_status = :verification_status
");

foreach ($statuses as $status) {
  $execParams = array_merge(
    [":verification_status" => $status],
    $params
  );

  $stmt->execute($execParams);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $label = $status;
  if ($status === "FALSE_REPORT") $label = "False Report";
  if ($status === "DUPLICATE") $label = "Duplicate";

  $items[] = [
    "status" => $status,
    "label" => ucwords(strtolower(str_replace("_", " ", $label))),
    "value" => (int)($row["c"] ?? 0)
  ];
}

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
  "items" => $items
]);