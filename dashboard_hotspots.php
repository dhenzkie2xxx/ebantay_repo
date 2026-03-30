<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

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

$limit = (int)($_GET["limit"] ?? 5);
if ($limit < 1) $limit = 5;
if ($limit > 20) $limit = 20;

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$provinceFilter = $role === "admin" ? $stationProvince : null;
$items = array_slice(get_computed_hotspots($pdo, $days, $provinceFilter), 0, $limit);

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_province" => $provinceFilter,
    "is_global" => $role === "super_admin"
  ],
  "items" => array_map(function($row) {
    return [
      "id" => (int)$row["id"],
      "name" => $row["name"],
      "radius_m" => (int)$row["radius_m"],
      "hotspot_type" => $row["hotspot_type"],
      "risk_level" => strtoupper((string)($row["risk_level"] ?? "UNKNOWN")),
      "highlight_color" => $row["highlight_color"] ?? null,
      "incident_count" => (int)($row["incident_count"] ?? 0),
      "panic_count" => (int)($row["panic_count"] ?? 0),
      "score" => (int)($row["score"] ?? 0),
      "last_detected_at" => $row["last_detected_at"]
        ? gmdate("Y-m-d H:i", strtotime($row["last_detected_at"]))
        : null
    ];
  }, $items)
]);