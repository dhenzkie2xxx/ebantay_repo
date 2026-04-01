<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  // -------------------------
  // HOTSPOTS
  // -------------------------
  $hotspotParams = [];
  $hotspotWhere = " WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL ";
  $hotspotWhere .= scope_location_where_clause(
    $scope,
    $hotspotParams,
    "province",
    "city_municipality",
    "barangay",
    "region",
    "hotspot"
  );

  $hotspotStmt = $pdo->prepare("
    SELECT
      id,
      name,
      region,
      province,
      city_municipality,
      barangay,
      lat,
      lng,
      radius_m,
      hotspot_type,
      risk_level,
      last_detected_at,
      created_at
    FROM crime_hotspots
    $hotspotWhere
    ORDER BY
      CASE risk_level
        WHEN 'HIGH' THEN 1
        WHEN 'MEDIUM' THEN 2
        WHEN 'LOW' THEN 3
        ELSE 4
      END,
      last_detected_at DESC,
      created_at DESC
  ");
  $hotspotStmt->execute($hotspotParams);
  $hotspots = $hotspotStmt->fetchAll(PDO::FETCH_ASSOC);

  // -------------------------
  // PANIC QUEUE
  // -------------------------
  $panicParams = [];
  $panicWhere = " WHERE p.status IN ('new', 'ack') AND p.lat IS NOT NULL AND p.lng IS NOT NULL ";
  $panicWhere .= scope_location_where_clause(
    $scope,
    $panicParams,
    "p.province",
    "p.city_municipality",
    "p.barangay",
    "p.region",
    "panic"
  );

  $panicStmt = $pdo->prepare("
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
  $panicStmt->execute($panicParams);
  $panic = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

  // -------------------------
  // VERIFIED INCIDENT POINTS
  // -------------------------
  $verifiedParams = [];
  $verifiedWhere = "
    WHERE verification_status = 'VERIFIED'
      AND incident_phase <> 'REJECTED'
      AND lat IS NOT NULL
      AND lng IS NOT NULL
  ";
  $verifiedWhere .= scope_location_where_clause(
    $scope,
    $verifiedParams,
    "province",
    "city_municipality",
    "barangay",
    "region",
    "verified"
  );

  $verifiedStmt = $pdo->prepare("
    SELECT
      id,
      title,
      incident_type,
      lat,
      lng,
      region,
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
  $verifiedStmt->execute($verifiedParams);
  $verified = $verifiedStmt->fetchAll(PDO::FETCH_ASSOC);

  // -------------------------
  // PENDING INCIDENT REPORTS
  // -------------------------
  $pendingParams = [];
  $pendingWhere = "
    WHERE verification_status = 'PENDING'
      AND incident_phase <> 'REJECTED'
      AND lat IS NOT NULL
      AND lng IS NOT NULL
  ";
  $pendingWhere .= scope_location_where_clause(
    $scope,
    $pendingParams,
    "province",
    "city_municipality",
    "barangay",
    "region",
    "pending"
  );

  $pendingStmt = $pdo->prepare("
    SELECT
      id,
      incident_code,
      title,
      incident_type,
      lat,
      lng,
      region,
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
  $pendingStmt->execute($pendingParams);
  $pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,

    "hotspots" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "name" => $r["name"],
        "region" => $r["region"] ?? null,
        "province" => $r["province"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "barangay" => $r["barangay"] ?? null,
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "radius_m" => isset($r["radius_m"]) ? (int)$r["radius_m"] : 0,
        "hotspot_type" => $r["hotspot_type"] ?? null,
        "risk_level" => strtoupper((string)($r["risk_level"] ?? "LOW")),
        "last_detected_at" => $r["last_detected_at"] ?? null,
        "created_at" => $r["created_at"] ?? null
      ];
    }, $hotspots),

    "panic_queue" => array_map(function ($r) {
      $fullName = trim(((string)($r["firstname"] ?? "")) . " " . ((string)($r["lastname"] ?? "")));
      return [
        "id" => (int)$r["id"],
        "level" => $r["level"] ?? null,
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "status" => $r["status"] ?? null,
        "created_at" => $r["created_at"] ?? null,
        "user_name" => $fullName !== "" ? $fullName : null,
        "region" => $r["region"] ?? null,
        "province" => $r["province"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "barangay" => $r["barangay"] ?? null
      ];
    }, $panic),

    "verified_points" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "title" => $r["title"] ?? null,
        "incident_type" => $r["incident_type"] ?? null,
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "region" => $r["region"] ?? null,
        "barangay" => $r["barangay"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "province" => $r["province"] ?? null,
        "date_reported" => $r["date_reported"] ?? null,
        "created_at" => $r["created_at"] ?? null
      ];
    }, $verified),

    "pending_reports" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "incident_code" => $r["incident_code"] ?? null,
        "title" => $r["title"] ?? null,
        "incident_type" => $r["incident_type"] ?? null,
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "region" => $r["region"] ?? null,
        "barangay" => $r["barangay"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "province" => $r["province"] ?? null,
        "verification_status" => $r["verification_status"] ?? null,
        "created_at" => $r["created_at"] ?? null
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