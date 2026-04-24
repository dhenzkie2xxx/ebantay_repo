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

try {
  $limit = (int)($_GET["limit"] ?? 5);
  if ($limit < 1) $limit = 5;
  if ($limit > 20) $limit = 20;

  $days = (int)($_GET["days"] ?? 30);
  if ($days < 1) $days = 30;
  if ($days > 365) $days = 365;

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

  $computed = get_computed_hotspots(
    $pdo,
    $days,
    $provinceFilter ?: null,
    $cityFilter ?: null,
    $role,
    null
  );

  $items = array_slice($computed, 0, $limit);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "filters" => [
      "limit" => $limit,
      "days" => $days
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
        "score" => isset($row["score"]) ? (int)$row["score"] : 0,
        "area_m2" => isset($row["area_m2"]) ? (float)$row["area_m2"] : 0,
        "density_value" => isset($row["density_value"]) ? (float)$row["density_value"] : 0,
        "density_per_km2" => isset($row["density_per_km2"]) ? (float)$row["density_per_km2"] : 0,
        "density_level" => strtoupper((string)($row["density_level"] ?? "LOW")),
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