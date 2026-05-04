<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/location_resolver.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function norm_text($v): ?string {
  $v = trim((string)($v ?? ""));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v === "" ? null : $v;
}

$q = trim($_GET["q"] ?? "");
$mode = strtoupper(trim($_GET["mode"] ?? "ALL")); // ALL | NEW | BLOTTERED

$role = (string)($AUTH_USER["role"] ?? "");
$isSuperAdmin = $role === "super_admin";

$stationProvince = norm_text($AUTH_USER["station_province"] ?? null);
$stationCity = norm_text($AUTH_USER["station_city_municipality"] ?? null);
$stationRegion = norm_text($AUTH_USER["station_region"] ?? null);

if (!$isSuperAdmin) {
  $canon = canonicalize_scope($pdo, $stationRegion, $stationProvince, $stationCity);

  if (empty($canon["ok"])) {
    out(403, [
      "ok" => false,
      "message" => "Unable to resolve your station city/municipality scope."
    ]);
  }

  $stationProvince = $canon["province"];
  $stationCity = $canon["city_municipality"];
}

$where = " WHERE 1=1 ";
$params = [];

/*
  Manuscript-aligned blotter rule:
  Only VERIFIED + RESOLVED incidents can proceed to BLOTTERED.

  Important:
  RESOLVED is checked using incident_phase, not case_status.
*/
if ($mode === "NEW") {
  $where .= "
    AND verification_status = 'VERIFIED'
    AND incident_phase = 'RESOLVED'
    AND (blotter_entry_number IS NULL OR blotter_entry_number = '')
  ";
} elseif ($mode === "BLOTTERED") {
  $where .= "
    AND incident_phase = 'BLOTTERED'
  ";
} else {
  $where .= "
    AND (
      (
        verification_status = 'VERIFIED'
        AND incident_phase = 'RESOLVED'
        AND (blotter_entry_number IS NULL OR blotter_entry_number = '')
      )
      OR incident_phase = 'BLOTTERED'
    )
  ";
}

/*
  Station Admin scope:
  Only show blotter candidates within the admin station's
  province + city/municipality.
*/
if (!$isSuperAdmin) {
  $where .= "
    AND LOWER(TRIM(province)) = LOWER(TRIM(?))
    AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
  ";
  $params[] = $stationProvince;
  $params[] = $stationCity;
}

if ($q !== "") {
  $where .= "
    AND (
      incident_code LIKE ?
      OR title LIKE ?
      OR incident_type LIKE ?
      OR barangay LIKE ?
      OR city_municipality LIKE ?
      OR blotter_entry_number LIKE ?
      OR irf_entry_number LIKE ?
    )
  ";
  $like = "%{$q}%";
  array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$sql = "
  SELECT
    id,
    incident_code,
    title,
    incident_type,
    barangay,
    city_municipality,
    province,
    region,
    verification_status,
    incident_phase,
    case_status,
    blotter_entry_number,
    irf_entry_number,
    date_reported,
    created_at
  FROM incident_reports
  $where
  ORDER BY
    CASE WHEN incident_phase = 'BLOTTERED' THEN 0 ELSE 1 END,
    created_at DESC
  LIMIT 200
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => [
      "is_super_admin" => $isSuperAdmin,
      "province" => $isSuperAdmin ? null : $stationProvince,
      "city_municipality" => $isSuperAdmin ? null : $stationCity
    ],
    "items" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "incident_code" => $r["incident_code"],
        "title" => $r["title"],
        "incident_type" => $r["incident_type"],
        "barangay" => $r["barangay"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"],
        "region" => $r["region"],
        "verification_status" => $r["verification_status"],
        "incident_phase" => $r["incident_phase"],
        "case_status" => $r["case_status"],
        "blotter_entry_number" => $r["blotter_entry_number"],
        "irf_entry_number" => $r["irf_entry_number"],
        "date_reported" => $r["date_reported"],
        "created_at" => $r["created_at"]
      ];
    }, $rows)
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}