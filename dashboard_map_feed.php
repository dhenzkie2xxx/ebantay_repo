<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

function is_valid_date_ymd($date){
  if(!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map("intval", explode("-", $date));
  return checkdate($m, $d, $y);
}

function map_feed_resolve_weight(array $r): float {
  if (
    isset($r["severity_weight"]) &&
    is_numeric($r["severity_weight"]) &&
    (float)$r["severity_weight"] > 0
  ) {
    return round((float)$r["severity_weight"], 2);
  }

  if (
    isset($r["severity_score"]) &&
    is_numeric($r["severity_score"]) &&
    (float)$r["severity_score"] > 0
  ) {
    return round((float)$r["severity_score"], 2);
  }

  return round(hotspot_crime_weight(
    $r["incident_type"] ?? "",
    $r["crime_category"] ?? ""
  ), 2);
}

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

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

  $dateExpr = "COALESCE(ir.date_reported, ir.created_at)";
  $verifiedDateSql = "";
  $verifiedParams = [];

  $filterMode = "days";
  $periodLabel = "Last " . $days . " days";

  if ($mode === "year" && preg_match('/^\d{4}$/', (string)$year)) {
    $filterMode = "year";
    $year = (int)$year;

    $verifiedDateSql = "
      AND $dateExpr >= :year_start
      AND $dateExpr < :year_end
    ";

    $verifiedParams[":year_start"] = $year . "-01-01 00:00:00";
    $verifiedParams[":year_end"] = ($year + 1) . "-01-01 00:00:00";

    $periodLabel = "Year " . $year;

  } elseif ($mode === "custom" && is_valid_date_ymd($from) && is_valid_date_ymd($to)) {
    if (strtotime($from) > strtotime($to)) {
      http_response_code(400);
      echo json_encode([
        "ok" => false,
        "message" => "Invalid date range. From date must not be later than To date."
      ]);
      exit;
    }

    $filterMode = "custom";

    $verifiedDateSql = "
      AND $dateExpr >= :date_from
      AND $dateExpr < DATE_ADD(:date_to, INTERVAL 1 DAY)
    ";

    $verifiedParams[":date_from"] = $from . " 00:00:00";
    $verifiedParams[":date_to"] = $to . " 00:00:00";

    $periodLabel = $from . " to " . $to;

  } else {
    $filterMode = "days";

    $verifiedDateSql = "
      AND $dateExpr >= (UTC_TIMESTAMP() - INTERVAL :verified_days DAY)
    ";

    $verifiedParams[":verified_days"] = $days;
  }

  /* ---------------- HOTSPOTS ---------------- */

  $provinceFilter = null;
  $cityFilter = null;
  $hotspotRole = !empty($scope["is_global"]) ? "super_admin" : "admin";

  if (empty($scope["is_global"])) {
    $provinceFilter = trim((string)($scope["station_province"] ?? ""));
    $cityFilter = trim((string)($scope["station_city_municipality"] ?? ""));
  }

  /*
    IMPORTANT:
    get_computed_hotspots() currently accepts days, not exact date range.
    Dashboard keeps days behavior.
    DataAnalytics year/custom uses an equivalent day window without changing hotspot_lib.php yet.
  */
  $hotspotDays = $days;

  if ($filterMode === "year") {
    $hotspotDays = 365;
  }

  if ($filterMode === "custom" && isset($verifiedParams[":date_from"], $verifiedParams[":date_to"])) {
    $diffDays = (strtotime($verifiedParams[":date_to"]) - strtotime($verifiedParams[":date_from"])) / 86400;
    $hotspotDays = max(7, min(365, (int)$diffDays + 1));
  }

  $hotspots = get_computed_hotspots(
  $pdo,
  $hotspotDays,
  $provinceFilter ?: null,
  $cityFilter ?: null,
  $hotspotRole,
  null,
  $filterMode === "custom" || $filterMode === "year" ? ($filterMode === "year" ? $year . "-01-01" : $from) : null,
  $filterMode === "custom" || $filterMode === "year" ? ($filterMode === "year" ? $year . "-12-31" : $to) : null
);

  /* ------------ PANIC QUEUE ------------ */
  /*
    Keep all active panic requests.
    This should not be limited by analytics date range because active panic requests are operational.
  */

  $panicParams = [];
  $panicWhere = "
    WHERE p.status IN ('new','ack')
      AND p.lat IS NOT NULL
      AND p.lng IS NOT NULL
  ";

  $panicWhere .= scope_where_clause(
    "p.province",
    $scope,
    $panicParams,
    ":panic_province"
  );

  $panicWhere .= scope_city_where_clause(
    "p.city_municipality",
    $scope,
    $panicParams,
    ":panic_city"
  );

  $stmt = $pdo->prepare("
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
    ORDER BY p.created_at DESC
    LIMIT 200
  ");
  $stmt->execute($panicParams);
  $panic = $stmt->fetchAll(PDO::FETCH_ASSOC);

  /* -------- VERIFIED INCIDENTS (SEVERITY HEATMAP) -------- */

  $verifiedWhere = "
    WHERE ir.verification_status = 'VERIFIED'
      AND ir.incident_phase IN (
        'RESOLVED',
        'BLOTTERED',
        'UNDER_INVESTIGATION',
        'FILED_IN_COURT'
      )
      AND ir.lat IS NOT NULL
      AND ir.lng IS NOT NULL
      $verifiedDateSql
  ";

  $verifiedWhere .= scope_where_clause(
    "ir.province",
    $scope,
    $verifiedParams,
    ":verified_province"
  );

  $verifiedWhere .= scope_city_where_clause(
    "ir.city_municipality",
    $scope,
    $verifiedParams,
    ":verified_city"
  );

  $stmt = $pdo->prepare("
    SELECT
      ir.id,
      ir.title,
      ir.incident_type,
      ir.crime_category,
      ir.lat,
      ir.lng,
      ir.region,
      ir.barangay,
      ir.city_municipality,
      ir.province,
      ir.date_reported,
      ir.created_at,
      ir.severity_score,
      ir.crime_type_id,
      ct.severity_weight
    FROM incident_reports ir
    LEFT JOIN crime_types ct
      ON (
        ct.id = ir.crime_type_id
        OR UPPER(TRIM(ct.crime_name)) = UPPER(TRIM(ir.incident_type))
      )
      AND ct.is_active = 1
    $verifiedWhere
    ORDER BY COALESCE(ir.date_reported, ir.created_at) DESC
    LIMIT 1000
  ");

  $stmt->execute($verifiedParams);
  $verified = $stmt->fetchAll(PDO::FETCH_ASSOC);

  /* -------- PENDING -------- */
  /*
    Keep pending reports all-time.
    These are operational records, not historical analytics records.
  */

  $pendingParams = [];

  $pendingWhere = "
    WHERE verification_status = 'PENDING'
      AND incident_phase <> 'REJECTED'
      AND lat IS NOT NULL
      AND lng IS NOT NULL
  ";

  $pendingWhere .= scope_where_clause(
    "province",
    $scope,
    $pendingParams,
    ":pending_province"
  );

  $pendingWhere .= scope_city_where_clause(
    "city_municipality",
    $scope,
    $pendingParams,
    ":pending_city"
  );

  $stmt = $pdo->prepare("
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

  $stmt->execute($pendingParams);
  $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "filters" => [
      "mode" => $filterMode,
      "days" => $filterMode === "days" ? $days : null,
      "year" => $filterMode === "year" ? (int)$year : null,
      "from" => $filterMode === "custom" ? $from : null,
      "to" => $filterMode === "custom" ? $to : null,
      "period_label" => $periodLabel,
      "hotspot_days" => $hotspotDays
    ],

    "hotspots" => $hotspots,

    "panic_queue" => array_map(function ($r) {
      $name = trim(
        ((string)($r["firstname"] ?? "")) . " " .
        ((string)($r["lastname"] ?? ""))
      );

      return [
        "id" => (int)$r["id"],
        "level" => $r["level"],
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "status" => $r["status"],
        "created_at" => $r["created_at"],
        "user_name" => $name !== "" ? $name : null,
        "region" => $r["region"],
        "province" => $r["province"],
        "city_municipality" => $r["city_municipality"],
        "barangay" => $r["barangay"]
      ];
    }, $panic),

    "verified_points" => array_map(function ($r) {
      $weight = map_feed_resolve_weight($r);

      return [
        "id" => (int)$r["id"],
        "title" => $r["title"],
        "incident_type" => $r["incident_type"],
        "crime_category" => $r["crime_category"],

        "crime_type_id" => $r["crime_type_id"] !== null ? (int)$r["crime_type_id"] : null,
        "stored_severity_score" => $r["severity_score"] !== null ? (float)$r["severity_score"] : null,
        "crime_type_severity_weight" => $r["severity_weight"] !== null ? (float)$r["severity_weight"] : null,

        "severity_score" => $weight,
        "weight" => $weight,

        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "region" => $r["region"],
        "barangay" => $r["barangay"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"],
        "date_reported" => $r["date_reported"],
        "created_at" => $r["created_at"]
      ];
    }, $verified),

    "pending_reports" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "incident_code" => $r["incident_code"],
        "title" => $r["title"],
        "incident_type" => $r["incident_type"],
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "region" => $r["region"],
        "barangay" => $r["barangay"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"],
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