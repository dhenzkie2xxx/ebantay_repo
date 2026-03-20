<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$hotspotStmt = $pdo->query("
  SELECT id, name, lat, lng, radius_m, hotspot_type, risk_level, last_detected_at
  FROM crime_hotspots
  WHERE active = 1
    AND lat IS NOT NULL
    AND lng IS NOT NULL
  ORDER BY
    CASE risk_level
      WHEN 'HIGH' THEN 1
      WHEN 'MEDIUM' THEN 2
      WHEN 'LOW' THEN 3
      ELSE 4
    END,
    last_detected_at DESC
");
$hotspots = $hotspotStmt->fetchAll(PDO::FETCH_ASSOC);

$panicStmt = $pdo->query("
  SELECT
    p.id,
    p.level,
    p.lat,
    p.lng,
    p.status,
    p.created_at,
    u.firstname,
    u.lastname
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  WHERE p.status IN ('new', 'ack')
    AND p.lat IS NOT NULL
    AND p.lng IS NOT NULL
  ORDER BY
    CASE p.level
      WHEN 'urgent' THEN 1
      WHEN 'alert' THEN 2
      ELSE 3
    END,
    p.created_at DESC
  LIMIT 200
");
$panic = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

$verifiedStmt = $pdo->query("
  SELECT
    id,
    title,
    incident_type,
    lat,
    lng,
    barangay,
    city_municipality,
    date_reported,
    created_at
  FROM incident_reports
  WHERE verification_status = 'VERIFIED'
    AND lat IS NOT NULL
    AND lng IS NOT NULL
  ORDER BY created_at DESC
  LIMIT 1000
");
$verified = $verifiedStmt->fetchAll(PDO::FETCH_ASSOC);

$pendingStmt = $pdo->query("
  SELECT
    id,
    incident_code,
    title,
    incident_type,
    lat,
    lng,
    barangay,
    city_municipality,
    verification_status,
    created_at
  FROM incident_reports
  WHERE verification_status = 'PENDING'
    AND lat IS NOT NULL
    AND lng IS NOT NULL
  ORDER BY created_at DESC
  LIMIT 300
");
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "hotspots" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "name" => $r["name"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "radius_m" => (int)$r["radius_m"],
      "hotspot_type" => $r["hotspot_type"],
      "risk_level" => $r["risk_level"],
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
      "verification_status" => $r["verification_status"],
      "created_at" => $r["created_at"]
    ];
  }, $pending)
]);