<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/location_resolver.php";

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

function reverse_geocode_scope(float $lat, float $lng): array {
  $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat="
    . urlencode((string)$lat)
    . "&lon="
    . urlencode((string)$lng);

  $opts = [
    "http" => [
      "method" => "GET",
      "header" =>
        "User-Agent: eBantay/1.0\r\n" .
        "Accept: application/json\r\n",
      "timeout" => 15
    ]
  ];

  $context = stream_context_create($opts);
  $raw = @file_get_contents($url, false, $context);
  if ($raw === false) {
    return ["ok" => false, "message" => "Reverse geocoding service unavailable"];
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    return ["ok" => false, "message" => "Invalid geocoding response"];
  }

  $addr = $json["address"] ?? [];

  $barangay = $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? $addr["city_district"]
    ?? "";

  $cityMunicipality = $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? "";

  $state = trim((string)($addr["state"] ?? ""));
  $region = $addr["region"] ?? $addr["state_district"] ?? "";
  $province = $addr["province"] ?? $addr["county"] ?? "";

  if ($province === "" && $state !== "") {
    $stateLower = strtolower($state);
    $looksLikeRegion =
      str_contains($stateLower, "region") ||
      str_contains($stateLower, "national capital region") ||
      $stateLower === "ncr" ||
      str_contains($stateLower, "metro manila");

    if ($looksLikeRegion) {
      if ($region === "") $region = $state;
    } else {
      $province = $state;
    }
  }

  return [
    "ok" => true,
    "address" => [
      "barangay" => normalize_scope_value($barangay),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region),
    ]
  ];
}

function canonicalize_scope_from_parts(PDO $pdo, ?string $region, ?string $province, ?string $cityMunicipality): array {
  $province = normalize_scope_value($province);
  $cityMunicipality = normalize_scope_value($cityMunicipality);
  $region = normalize_scope_value($region);

  if ($province === null || $cityMunicipality === null) {
    return [
      "ok" => false,
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
    ];
  }

  $canon = canonicalize_scope($pdo, $region, $province, $cityMunicipality);
  if (!$canon["ok"]) {
    return [
      "ok" => false,
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
    ];
  }

  return [
    "ok" => true,
    "region" => normalize_scope_value($canon["region"] ?? null),
    "province" => normalize_scope_value($canon["province"] ?? null),
    "city_municipality" => normalize_scope_value($canon["city_municipality"] ?? null),
  ];
}

function resolve_request_scope(PDO $pdo): array {
  $token = bearer_token();

  if ($token !== "") {
    $user = auth_get_user_by_token($pdo, $token);

    if ($user && !auth_check_token_expired($user)) {
      $role = (string)($user["role"] ?? "public");

      if ($role === "super_admin") {
        return [
          "source" => "auth",
          "role" => "super_admin",
          "user_id" => (int)$user["id"],
          "region" => null,
          "province" => null,
          "city_municipality" => null,
        ];
      }

      if ($role === "admin") {
        $canon = canonicalize_scope_from_parts(
          $pdo,
          $user["station_region"] ?? null,
          $user["station_province"] ?? null,
          $user["station_city_municipality"] ?? null
        );

        return [
          "source" => "auth",
          "role" => "admin",
          "user_id" => (int)$user["id"],
          "region" => $canon["region"] ?? normalize_scope_value($user["station_region"] ?? null),
          "province" => $canon["province"] ?? normalize_scope_value($user["station_province"] ?? null),
          "city_municipality" => $canon["city_municipality"] ?? normalize_scope_value($user["station_city_municipality"] ?? null),
        ];
      }

      if ($role === "citizen") {
        $queryProvince = normalize_scope_value($_GET["province"] ?? null);
        $queryCity = normalize_scope_value($_GET["city_municipality"] ?? null);
        $queryRegion = normalize_scope_value($_GET["region"] ?? null);

        if ($queryProvince !== null && $queryCity !== null) {
          $canon = canonicalize_scope_from_parts($pdo, $queryRegion, $queryProvince, $queryCity);

          return [
            "source" => "auth_query",
            "role" => "citizen",
            "user_id" => (int)$user["id"],
            "region" => $canon["region"] ?? $queryRegion,
            "province" => $canon["province"] ?? $queryProvince,
            "city_municipality" => $canon["city_municipality"] ?? $queryCity,
          ];
        }

        $lat = $_GET["lat"] ?? null;
        $lng = $_GET["lng"] ?? null;

        if (is_numeric($lat) && is_numeric($lng)) {
          $geo = reverse_geocode_scope((float)$lat, (float)$lng);
          if ($geo["ok"]) {
            $addr = $geo["address"] ?? [];
            $canon = canonicalize_scope_from_parts(
              $pdo,
              $addr["region"] ?? null,
              $addr["province"] ?? null,
              $addr["city_municipality"] ?? null
            );

            return [
              "source" => "auth_reverse_geocode",
              "role" => "citizen",
              "user_id" => (int)$user["id"],
              "region" => $canon["region"] ?? normalize_scope_value($addr["region"] ?? null),
              "province" => $canon["province"] ?? normalize_scope_value($addr["province"] ?? null),
              "city_municipality" => $canon["city_municipality"] ?? normalize_scope_value($addr["city_municipality"] ?? null),
            ];
          }
        }

        return [
          "source" => "auth",
          "role" => "citizen",
          "user_id" => (int)$user["id"],
          "region" => null,
          "province" => null,
          "city_municipality" => null,
        ];
      }
    }
  }

  $province = normalize_scope_value($_GET["province"] ?? null);
  $cityMunicipality = normalize_scope_value($_GET["city_municipality"] ?? null);
  $region = normalize_scope_value($_GET["region"] ?? null);

  if ($province !== null && $cityMunicipality !== null) {
    $canon = canonicalize_scope_from_parts($pdo, $region, $province, $cityMunicipality);

    return [
      "source" => "query",
      "role" => "public",
      "user_id" => null,
      "region" => $canon["region"] ?? $region,
      "province" => $canon["province"] ?? $province,
      "city_municipality" => $canon["city_municipality"] ?? $cityMunicipality,
    ];
  }

  $lat = $_GET["lat"] ?? null;
  $lng = $_GET["lng"] ?? null;

  if (is_numeric($lat) && is_numeric($lng)) {
    $geo = reverse_geocode_scope((float)$lat, (float)$lng);
    if ($geo["ok"]) {
      $addr = $geo["address"] ?? [];
      $canon = canonicalize_scope_from_parts(
        $pdo,
        $addr["region"] ?? null,
        $addr["province"] ?? null,
        $addr["city_municipality"] ?? null
      );

      return [
        "source" => "reverse_geocode",
        "role" => "public",
        "user_id" => null,
        "region" => $canon["region"] ?? normalize_scope_value($addr["region"] ?? null),
        "province" => $canon["province"] ?? normalize_scope_value($addr["province"] ?? null),
        "city_municipality" => $canon["city_municipality"] ?? normalize_scope_value($addr["city_municipality"] ?? null),
      ];
    }
  }

  return [
    "source" => "none",
    "role" => "public",
    "user_id" => null,
    "region" => null,
    "province" => null,
    "city_municipality" => null,
  ];
}

function append_scope_filter_for_incidents(
  string &$sql,
  array &$params,
  string $role,
  ?string $province,
  ?string $cityMunicipality,
  ?int $userId
): void {
  if ($role === "super_admin") {
    return;
  }

  if ($role === "admin") {
    if ($province !== null && $cityMunicipality !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
    }
    return;
  }

  if ($role === "citizen") {
    if ($province !== null && $cityMunicipality !== null && $userId !== null) {
      $sql .= "
        AND (
          (
            LOWER(TRIM(province)) = LOWER(TRIM(?))
            AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
          )
          OR reporter_user_id = ?
        )
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
      $params[] = $userId;
    } elseif ($province !== null && $cityMunicipality !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
    } elseif ($userId !== null) {
      $sql .= " AND reporter_user_id = ? ";
      $params[] = $userId;
    }
    return;
  }

  if ($province !== null && $cityMunicipality !== null) {
    $sql .= "
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ";
    $params[] = $province;
    $params[] = $cityMunicipality;
  }
}

function append_scope_filter_for_panic(
  string &$sql,
  array &$params,
  string $role,
  ?string $province,
  ?string $cityMunicipality,
  ?int $userId
): void {
  if ($role === "super_admin") {
    return;
  }

  if ($role === "admin") {
    if ($province !== null && $cityMunicipality !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
    }
    return;
  }

  if ($role === "citizen") {
    if ($province !== null && $cityMunicipality !== null && $userId !== null) {
      $sql .= "
        AND (
          (
            LOWER(TRIM(province)) = LOWER(TRIM(?))
            AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
          )
          OR user_id = ?
        )
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
      $params[] = $userId;
    } elseif ($province !== null && $cityMunicipality !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $province;
      $params[] = $cityMunicipality;
    } elseif ($userId !== null) {
      $sql .= " AND user_id = ? ";
      $params[] = $userId;
    }
    return;
  }

  if ($province !== null && $cityMunicipality !== null) {
    $sql .= "
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ";
    $params[] = $province;
    $params[] = $cityMunicipality;
  }
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
$days = max(1, min(365, $days));

$category = trim($_GET["category"] ?? "");
$group = isset($_GET["group"]) ? (int)$_GET["group"] : 0;

$minLat = isset($_GET["minLat"]) ? (float)$_GET["minLat"] : null;
$maxLat = isset($_GET["maxLat"]) ? (float)$_GET["maxLat"] : null;
$minLng = isset($_GET["minLng"]) ? (float)$_GET["minLng"] : null;
$maxLng = isset($_GET["maxLng"]) ? (float)$_GET["maxLng"] : null;

$scope = resolve_request_scope($pdo);

$role = (string)($scope["role"] ?? "public");
$userId = isset($scope["user_id"]) ? (int)$scope["user_id"] : null;
$provinceFilter = normalize_scope_value($scope["province"] ?? null);
$cityFilter = normalize_scope_value($scope["city_municipality"] ?? null);
$regionFilter = normalize_scope_value($scope["region"] ?? null);

$bboxSql = "";
$bboxParams = [];
if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
  $bboxSql = " AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ";
  $bboxParams = [$minLat, $maxLat, $minLng, $maxLng];
}

try {
  $heatPoints = [];
  $pendingMarkers = [];

  if ($category === "" || strcasecmp($category, "Panic") !== 0) {
    $verifiedSql = "
      SELECT
        lat,
        lng,
        incident_type AS category,
        COUNT(*) AS total_reports,
        MAX(date_reported) AS latest_reported,
        SUM(CASE WHEN verification_status = 'VERIFIED' THEN 1 ELSE 0 END) AS verified_count,
        SUM(CASE WHEN incident_phase IN ('BLOTTERED', 'UNDER_INVESTIGATION', 'FILED_IN_COURT') THEN 1 ELSE 0 END) AS escalated_count,
        SUM(CASE WHEN is_hotspot_related = 1 THEN 1 ELSE 0 END) AS hotspot_related_count
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status = 'VERIFIED'
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
    ";

    $params = [$days];

    append_scope_filter_for_incidents($verifiedSql, $params, $role, $provinceFilter, $cityFilter, $userId);

    if ($category !== "") {
      $verifiedSql .= " AND incident_type = ? ";
      $params[] = $category;
    }

    $verifiedSql .= $bboxSql . " GROUP BY lat, lng, incident_type ";
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($verifiedSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $w = 1;

      $verifiedCount = (int)($r["verified_count"] ?? 0);
      $escalatedCount = (int)($r["escalated_count"] ?? 0);
      $hotspotRelatedCount = (int)($r["hotspot_related_count"] ?? 0);
      $totalReports = (int)($r["total_reports"] ?? 1);

      if ($verifiedCount > 0) $w += 1;
      if ($escalatedCount > 0) $w += 1;
      if ($hotspotRelatedCount > 0) $w += 1;
      if ($totalReports >= 2) $w += 1;
      if ($totalReports >= 3) $w += 1;

      $ageSec = time() - strtotime($r["latest_reported"]);
      if ($ageSec <= 86400) $w += 2;
      elseif ($ageSec <= 7 * 86400) $w += 1;

      $heatPoints[] = [
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "weight" => $w,
        "category" => $r["category"] ?: "Other",
        "source" => "incident_report",
      ];
    }

    $pendingSql = "
      SELECT
        lat,
        lng,
        incident_type AS category,
        MIN(id) AS id,
        MAX(date_reported) AS latest_reported
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status = 'PENDING'
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
    ";

    $params = [$days];

    append_scope_filter_for_incidents($pendingSql, $params, $role, $provinceFilter, $cityFilter, $userId);

    if ($category !== "") {
      $pendingSql .= " AND incident_type = ? ";
      $params[] = $category;
    }

    $pendingSql .= $bboxSql . " GROUP BY lat, lng, incident_type ";
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($pendingSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $pendingMarkers[] = [
        "id" => (int)$r["id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => $r["category"] ?: "Other",
        "marker_type" => "report",
        "verification_status" => "PENDING",
        "source" => "incident_report",
      ];
    }
  }

  if ($category === "" || strcasecmp($category, "Panic") === 0) {
    $panicSql = "
      SELECT
        lat,
        lng,
        level,
        MIN(id) AS id,
        MAX(created_at) AS latest_created_at
      FROM panic_requests
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND status <> 'resolved'
        AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
    ";

    $params = [$days];

    append_scope_filter_for_panic($panicSql, $params, $role, $provinceFilter, $cityFilter, $userId);

    $panicSql .= $bboxSql . " GROUP BY lat, lng, level ";
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($panicSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $pendingMarkers[] = [
        "id" => (int)$r["id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => "Panic",
        "marker_type" => "panic",
        "level" => $r["level"] ?: "alert",
        "source" => "panic_request",
      ];
    }
  }

  if ($group === 1) {
    $groupedData = [];
    foreach ($heatPoints as $p) {
      $cat = $p["category"] ?? "Unknown";
      if (!isset($groupedData[$cat])) $groupedData[$cat] = [];
      $groupedData[$cat][] = [
        "lat" => $p["lat"],
        "lng" => $p["lng"],
        "weight" => $p["weight"],
      ];
    }

    out(200, [
      "ok" => true,
      "days" => $days,
      "grouped" => true,
      "scope" => [
        "source" => $scope["source"],
        "role" => $role,
        "user_id" => $userId,
        "region" => $regionFilter,
        "province" => $provinceFilter,
        "city_municipality" => $cityFilter,
      ],
      "data" => $groupedData,
      "pending_markers" => $pendingMarkers,
    ]);
  }

  out(200, [
    "ok" => true,
    "days" => $days,
    "grouped" => false,
    "scope" => [
      "source" => $scope["source"],
      "role" => $role,
      "user_id" => $userId,
      "region" => $regionFilter,
      "province" => $provinceFilter,
      "city_municipality" => $cityFilter,
    ],
    "data" => $heatPoints,
    "pending_markers" => $pendingMarkers,
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage(),
  ]);
}