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

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$provinceFilter = $role === "admin" ? $stationProvince : null;
$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$hotspots = get_computed_hotspots($pdo, $days, $provinceFilter);

$panicSql = "
  SELECT
    p.id,
    p.level,
    p.lat,
    p.lng,
    p.status,
    p.created_at,
    p.region,
    p.province,
    p.city_municipality,
    p.barangay,
    p.assigned_station_id,
    u.firstname,
    u.lastname
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  WHERE p.status IN ('new', 'ack')
    AND p.lat IS NOT NULL
    AND p.lng IS NOT NULL
";
$panicParams = [];

if ($provinceFilter !== null) {
  $panicSql .= " AND LOWER(TRIM(p.province)) = LOWER(TRIM(:province)) ";
  $panicParams[":province"] = $provinceFilter;
}

$panicSql .= "
  ORDER BY
    CASE p.level
      WHEN 'urgent' THEN 1
      WHEN 'alert' THEN 2
      ELSE 3
    END,
    p.created_at DESC
  LIMIT 200
";

$panicStmt = $pdo->prepare($panicSql);
foreach ($panicParams as $k => $v) {
  $panicStmt->bindValue($k, $v);
}
$panicStmt->execute();
$panic = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

$verifiedSql = "
  SELECT
    id,
    title,
    incident_type,
    lat,
    lng,
    barangay,
    city_municipality,
    province,
    region,
    date_reported,
    created_at
  FROM incident_reports
  WHERE verification_status = 'VERIFIED'
    AND lat IS NOT NULL
    AND lng IS NOT NULL
";
$verifiedParams = [];

if ($provinceFilter !== null) {
  $verifiedSql .= " AND LOWER(TRIM(province)) = LOWER(TRIM(:province)) ";
  $verifiedParams[":province"] = $provinceFilter;
}

$verifiedSql .= "
  ORDER BY created_at DESC
  LIMIT 1000
";

$verifiedStmt = $pdo->prepare($verifiedSql);
foreach ($verifiedParams as $k => $v) {
  $verifiedStmt->bindValue($k, $v);
}
$verifiedStmt->execute();
$verified = $verifiedStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingSql = "
  SELECT
    id,
    incident_code,
    title,
    incident_type,
    lat,
    lng,
    barangay,
    city_municipality,
    province,
    region,
    verification_status,
    created_at
  FROM incident_reports
  WHERE verification_status = 'PENDING'
    AND lat IS NOT NULL
    AND lng IS NOT NULL
";
$pendingParams = [];

if ($provinceFilter !== null) {
  $pendingSql .= " AND LOWER(TRIM(province)) = LOWER(TRIM(:province)) ";
  $pendingParams[":province"] = $provinceFilter;
}

$pendingSql .= "
  ORDER BY created_at DESC
  LIMIT 300
";

$pendingStmt = $pdo->prepare($pendingSql);
foreach ($pendingParams as $k => $v) {
  $pendingStmt->bindValue($k, $v);
}
$pendingStmt->execute();
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_province" => $provinceFilter,
    "is_global" => $role === "super_admin"
  ],
  "hotspots" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "name" => $r["name"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "radius_m" => (int)$r["radius_m"],
      "hotspot_type" => $r["hotspot_type"],
      "risk_level" => $r["risk_level"],
      "highlight_color" => $r["highlight_color"] ?? null,
      "incident_count" => (int)($r["incident_count"] ?? 0),
      "panic_count" => (int)($r["panic_count"] ?? 0),
      "score" => (int)($r["score"] ?? 0),
      "last_detected_at" => $r["last_detected_at"]
    ];
  }, $hotspots),

  "panic_queue" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "level" => $r["level"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "status" => $r["status"],
      "created_at" => $r["created_at"],
      "region" => $r["region"],
      "province" => $r["province"],
      "city_municipality" => $r["city_municipality"],
      "barangay" => $r["barangay"],
      "assigned_station_id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
      "user_name" => trim(($r["firstname"] ?? "") . " " . ($r["lastname"] ?? ""))
    ];
  }, $panic),

  "verified_points" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "title" => $r["title"],
      "incident_type" => $r["incident_type"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "region" => $r["region"],
      "date_reported" => $r["date_reported"],
      "created_at" => $r["created_at"]
    ];
  }, $verified),

  "pending_reports" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "incident_code" => $r["incident_code"],
      "title" => $r["title"],
      "incident_type" => $r["incident_type"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "region" => $r["region"],
      "verification_status" => $r["verification_status"],
      "created_at" => $r["created_at"]
    ];
  }, $pending)
]);