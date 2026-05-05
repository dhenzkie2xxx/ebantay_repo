<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function is_valid_date_ymd($date) {
  if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map("intval", explode("-", $date));
  return checkdate($m, $d, $y);
}

try {
  $limit = (int)($_GET["limit"] ?? 5);
  if ($limit < 1) $limit = 5;
  if ($limit > 20) $limit = 20;

  /*
  |--------------------------------------------------------------------------
  | FILTER MODE
  |--------------------------------------------------------------------------
  | Dashboard behavior:
  |   ?days=30
  |
  | DataAnalytics behavior:
  |   ?mode=year&year=2026
  |   ?mode=custom&from=2026-01-01&to=2026-05-06
  |--------------------------------------------------------------------------
  */

  $mode = $_GET["mode"] ?? "";
  $year = $_GET["year"] ?? "";
  $from = $_GET["from"] ?? "";
  $to = $_GET["to"] ?? "";

  $days = (int)($_GET["days"] ?? 30);
  if ($days < 1) $days = 30;
  if ($days > 365) $days = 365;

  $filterMode = "days";
  $periodLabel = "Last " . $days . " days";

  $analyticsFrom = null;
  $analyticsTo = null;

  if ($mode === "year" && preg_match('/^\d{4}$/', (string)$year)) {
    $filterMode = "year";
    $year = (int)$year;

    $analyticsFrom = $year . "-01-01";
    $analyticsTo = $year . "-12-31";
    $periodLabel = "Year " . $year;

  } elseif ($mode === "custom" && is_valid_date_ymd($from) && is_valid_date_ymd($to)) {
    if (strtotime($from) > strtotime($to)) {
      out(400, [
        "ok" => false,
        "message" => "Invalid date range. From date must not be later than To date."
      ]);
    }

    $filterMode = "custom";
    $analyticsFrom = $from;
    $analyticsTo = $to;
    $periodLabel = $from . " to " . $to;
  }

  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $provinceFilter = null;
  $cityFilter = null;
  $role = !empty($scope["is_global"]) ? "super_admin" : "admin";

  if (!$scope["is_global"]) {
    $provinceFilter = trim((string)($scope["station_province"] ?? ""));
    $cityFilter = trim((string)($scope["station_city_municipality"] ?? ""));

    if ($provinceFilter === "" || $cityFilter === "") {
      out(422, [
        "ok" => false,
        "message" => "Station scope is incomplete."
      ]);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | IMPORTANT
  |--------------------------------------------------------------------------
  | If $analyticsFrom and $analyticsTo are null:
  |   - hotspot_lib.php keeps historical incident explanation behavior.
  |
  | If they have values:
  |   - DataAnalytics hotspot results become dynamic by selected period.
  |--------------------------------------------------------------------------
  */

  $computed = get_computed_hotspots(
    $pdo,
    $days,
    $provinceFilter ?: null,
    $cityFilter ?: null,
    $role,
    null,
    $analyticsFrom,
    $analyticsTo
  );

  $items = array_slice($computed, 0, $limit);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "filters" => [
      "limit" => $limit,
      "mode" => $filterMode,
      "days" => $filterMode === "days" ? $days : null,
      "year" => $filterMode === "year" ? (int)$year : null,
      "from" => $filterMode === "custom" ? $from : null,
      "to" => $filterMode === "custom" ? $to : null,
      "period_label" => $periodLabel
    ],
    "items" => array_map(function ($row) {
      return [
        "id" => (int)$row["id"],
        "name" => $row["name"] ?? null,
        "region" => $row["region"] ?? null,
        "province" => $row["province"] ?? null,
        "city_municipality" => $row["city_municipality"] ?? null,
        "barangay" => $row["barangay"] ?? null,
        "lat" => $row["lat"] !== null ? (float)$row["lat"] : null,
        "lng" => $row["lng"] !== null ? (float)$row["lng"] : null,
        "radius_m" => isset($row["radius_m"]) ? (int)$row["radius_m"] : 0,
        "hotspot_type" => $row["hotspot_type"] ?? null,
        "risk_level" => strtoupper((string)($row["risk_level"] ?? "LOW")),
        "highlight_color" => $row["highlight_color"] ?? "none",
        "incident_count" => isset($row["incident_count"]) ? (int)$row["incident_count"] : 0,
        "panic_count" => isset($row["panic_count"]) ? (int)$row["panic_count"] : 0,
        "panic_score" => isset($row["panic_score"]) ? (int)$row["panic_score"] : 0,
        "point_count" => isset($row["point_count"]) ? (int)$row["point_count"] : 0,
        "severity_score_total" => isset($row["severity_score_total"]) ? (float)$row["severity_score_total"] : 0,
        "max_crime_weight" => isset($row["max_crime_weight"]) ? (float)$row["max_crime_weight"] : 0,
        "score" => isset($row["score"]) ? (float)$row["score"] : 0,
        "area_m2" => isset($row["area_m2"]) ? (float)$row["area_m2"] : 0,
        "density_value" => isset($row["density_value"]) ? (float)$row["density_value"] : 0,
        "density_per_km2" => isset($row["density_per_km2"]) ? (float)$row["density_per_km2"] : 0,
        "density_level" => strtoupper((string)($row["density_level"] ?? "LOW")),
        "crime_breakdown" => $row["crime_breakdown"] ?? [],
        "last_detected_at" => $row["last_detected_at"] ?? null,
        "created_at" => $row["created_at"] ?? null
      ];
    }, $items)
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}