<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $params = [];
  $hotspotWhere = " WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL ";
  $panicWhere = " WHERE p.status IN ('new', 'ack') AND p.lat IS NOT NULL AND p.lng IS NOT NULL ";
  $verifiedWhere = " WHERE verification_status = 'VERIFIED' AND lat IS NOT NULL AND lng IS NOT NULL ";
  $pendingWhere = " WHERE verification_status = 'PENDING' AND lat IS NOT NULL AND lng IS NOT NULL ";

  $hotspotWhere .= scope_where_clause("province", $scope, $params, ":hotspot_province");
  $panicWhere .= scope_where_clause("p.province", $scope, $params, ":panic_province");
  $verifiedWhere .= scope_where_clause("province", $scope, $params, ":verified_province");
  $pendingWhere .= scope_where_clause("province", $scope, $params, ":pending_province");

  $hotspotStmt = $pdo->prepare("
    SELECT id, name, lat, lng, radius_m, hotspot_type, risk_level, last_detected_at, province
    FROM crime_hotspots
    $hotspotWhere
    ORDER BY
      CASE risk_level
        WHEN 'HIGH' THEN 1
        WHEN 'MEDIUM' THEN 2
        WHEN 'LOW' THEN 3
        ELSE 4
      END,
      last_detected_at DESC
  ");
  $hotspotStmt->execute($params);
  $hotspots = $hotspotStmt->fetchAll(PDO::FETCH_ASSOC);

  $panicStmt = $pdo->prepare("
    SELECT
      p.id,
      p.level,
      p.lat,
      p.lng,
      p.status,
      p.created_at,
      p.province,
      p.city_municipality,
      p.barangay,
      u.firstname,
      u.lastname
    FROM panic_requests p
    JOIN users u ON u.id = p.user_id
    $panicWhere
    ORDER BY
      CASE p.level
        WHEN 'urgent' THEN 1
        WHEN 'alert' THEN 2
        ELSE 3
      END,
      p.created_at DESC
    LIMIT 200
  ");
  $panicStmt->execute($params);
  $panic = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

  $verifiedStmt = $pdo->prepare("
    SELECT
      id,
      title,
      incident_type,
      lat,
      lng,
      barangay,
      city_municipality,
      province,
      date_reported,
      created_at
    FROM incident_reports
    $verifiedWhere
    ORDER BY created_at DESC
    LIMIT 1000
  ");
  $verifiedStmt->execute($params);
  $verified = $verifiedStmt->fetchAll(PDO::FETCH_ASSOC);

  $pendingStmt = $pdo->prepare("
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
      verification_status,
      created_at
    FROM incident_reports
    $pendingWhere
    ORDER BY created_at DESC
    LIMIT 300
  ");
  $pendingStmt->execute($params);
  $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "hotspots" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "name" => $r["name"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "radius_m" => (int)$r["radius_m"],
        "hotspot_type" => $r["hotspot_type"],
        "risk_level" => $r["risk_level"],
        "last_detected_at" => $r["last_detected_at"],
        "province" => $r["province"] ?? null
      ];
    }, $hotspots),

    "panic_queue" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "level" => $r["level"],
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "status" => $r["status"],
        "created_at" => $r["created_at"],
        "user_name" => trim(($r["firstname"] ?? "") . " " . ($r["lastname"] ?? "")),
        "province" => $r["province"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "barangay" => $r["barangay"] ?? null
      ];
    }, $panic),

    "verified_points" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "title" => $r["title"],
        "incident_type" => $r["incident_type"],
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "barangay" => $r["barangay"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"] ?? null,
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
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "barangay" => $r["barangay"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"] ?? null,
        "verification_status" => $r["verification_status"],
        "created_at" => $r["created_at"]
      ];
    }, $pending)
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