<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $days = (int)($_GET["days"] ?? 30);
  if ($days < 1) $days = 30;
  if ($days > 365) $days = 365;

  // -------------------------
  // HOTSPOTS (computed with density)
  // -------------------------
  $provinceFilter = null;
  $cityFilter = null;
  $hotspotRole = !empty($scope["is_global"]) ? "super_admin" : "admin";

  if (empty($scope["is_global"])) {
    $provinceFilter = trim((string)($scope["station_province"] ?? ""));
    $cityFilter = trim((string)($scope["station_city_municipality"] ?? ""));

    if ($provinceFilter === "" || $cityFilter === "") {
      throw new Exception("Station scope is incomplete.");
    }
  }

  $hotspots = get_computed_hotspots(
    $pdo,
    $days,
    $provinceFilter ?: null,
    $cityFilter ?: null,
    $hotspotRole,
    null
  );

  // -------------------------
  // PANIC QUEUE
  // -------------------------
  $panicParams = [];
  $panicWhere = " WHERE p.status IN ('new', 'ack') AND p.lat IS NOT NULL AND p.lng IS NOT NULL ";
  $panicWhere .= scope_where_clause("p.province", $scope, $panicParams, ":panic_province");
  $panicWhere .= scope_city_where_clause("p.city_municipality", $scope, $panicParams, ":panic_city");

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
  $verifiedWhere .= scope_where_clause("province", $scope, $verifiedParams, ":verified_province");
  $verifiedWhere .= scope_city_where_clause("city_municipality", $scope, $verifiedParams, ":verified_city");

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
  $pendingWhere .= scope_where_clause("province", $scope, $pendingParams, ":pending_province");
  $pendingWhere .= scope_city_where_clause("city_municipality", $scope, $pendingParams, ":pending_city");

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
    "filters" => [
      "days" => $days
    ],

    "hotspots" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "name" => $r["name"] ?? null,
        "region" => $r["region"] ?? null,
        "province" => $r["province"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "barangay" => $r["barangay"] ?? null,
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "radius_m" => isset($r["radius_m"]) ? (int)$r["radius_m"] : 0,
        "hotspot_type" => $r["hotspot_type"] ?? null,
        "risk_level" => strtoupper((string)($r["risk_level"] ?? "LOW")),
        "highlight_color" => $r["highlight_color"] ?? "none",
        "incident_count" => isset($r["incident_count"]) ? (int)$r["incident_count"] : 0,
        "panic_count" => isset($r["panic_count"]) ? (int)$r["panic_count"] : 0,
        "panic_score" => isset($r["panic_score"]) ? (int)$r["panic_score"] : 0,
        "point_count" => isset($r["point_count"]) ? (int)$r["point_count"] : 0,
        "score" => isset($r["score"]) ? (int)$r["score"] : 0,
        "area_m2" => isset($r["area_m2"]) ? (float)$r["area_m2"] : 0,
        "density_value" => isset($r["density_value"]) ? (float)$r["density_value"] : 0,
        "density_per_km2" => isset($r["density_per_km2"]) ? (float)$r["density_per_km2"] : 0,
        "density_level" => strtoupper((string)($r["density_level"] ?? "LOW")),
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