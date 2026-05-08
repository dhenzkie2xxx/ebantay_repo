<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/location_resolver.php";
require_once __DIR__ . "/station_assignment_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value === "" ? null : $value;
}

function looks_like_region_name(?string $value): bool {
  $value = strtolower(trim((string)$value));
  if ($value === "") return false;

  return
    str_contains($value, "region") ||
    str_contains($value, "national capital region") ||
    $value === "ncr" ||
    str_contains($value, "metro manila");
}

function http_get_json(string $url): array {
  $headers = [
    "User-Agent: eBantay/1.0",
    "Accept: application/json",
  ];

  if (function_exists("curl_init")) {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw !== false && $status >= 200 && $status < 300) {
      $json = json_decode($raw, true);
      if (is_array($json)) {
        return ["ok" => true, "json" => $json];
      }

      return ["ok" => false, "message" => "Invalid geocoding JSON response"];
    }

    return [
      "ok" => false,
      "message" => "Geocoding request failed: " . ($err ?: "HTTP " . $status)
    ];
  }

  $opts = [
    "http" => [
      "method" => "GET",
      "header" => "User-Agent: eBantay/1.0\r\nAccept: application/json\r\n",
      "timeout" => 12
    ],
    "ssl" => [
      "verify_peer" => true,
      "verify_peer_name" => true
    ]
  ];

  $context = stream_context_create($opts);
  $raw = @file_get_contents($url, false, $context);

  if ($raw === false) {
    $error = error_get_last();
    return [
      "ok" => false,
      "message" => "Geocoding request failed" . (!empty($error["message"]) ? ": " . $error["message"] : "")
    ];
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    return ["ok" => false, "message" => "Invalid geocoding JSON response"];
  }

  return ["ok" => true, "json" => $json];
}

function reverse_geocode_scope(PDO $pdo, float $lat, float $lng): array {
  $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat="
    . urlencode((string)$lat)
    . "&lon="
    . urlencode((string)$lng);

  $http = http_get_json($url);

  if (!$http["ok"]) {
    return [
      "ok" => false,
      "message" => $http["message"]
    ];
  }

  $json = $http["json"];
  $addr = $json["address"] ?? [];

  $barangay =
    $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? $addr["city_district"]
    ?? "";

  $cityMunicipality =
    $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? $addr["city_district"]
    ?? "";

  $state = trim((string)($addr["state"] ?? ""));

  $region =
    $addr["region"]
    ?? $addr["state_district"]
    ?? "";

  $province =
    $addr["province"]
    ?? $addr["county"]
    ?? "";

  if ($province === "" && $state !== "") {
    if (looks_like_region_name($state)) {
      if ($region === "") {
        $region = $state;
      }
    } else {
      $province = $state;
    }
  }

  if ($cityMunicipality !== "") {
    $fromCity = resolve_scope_from_city($pdo, $cityMunicipality);

    if (!empty($fromCity["ok"])) {
      $cityMunicipality = $fromCity["city_municipality"] ?: $cityMunicipality;

      if ($province === "") {
        $province = $fromCity["province"] ?? "";
      }

      if ($region === "" || looks_like_region_name($region)) {
        $region = $fromCity["region"] ?? $region;
      }
    }
  }

  $canon = canonicalize_scope($pdo, $region, $province, $cityMunicipality);

  if (!empty($canon["ok"])) {
    $region = $canon["region"] ?? $region;
    $province = $canon["province"] ?? $province;
    $cityMunicipality = $canon["city_municipality"] ?? $cityMunicipality;
  }

  return [
    "ok" => true,
    "address" => [
      "barangay" => normalize_scope_value($barangay),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region)
    ],
    "raw" => [
      "display_name" => $json["display_name"] ?? null,
      "address" => $addr
    ]
  ];
}

function format_station_response(array $nearest, string $assignmentRule): array {
  return [
    "id" => (int)$nearest["id"],
    "station_name" => $nearest["station_name"],
    "station_code" => $nearest["station_code"] ?? null,
    "station_type" => $nearest["station_type"] ?? null,
    "region" => $nearest["region"] ?? null,
    "province" => $nearest["province"] ?? null,
    "city_municipality" => $nearest["city_municipality"] ?? null,
    "barangay" => $nearest["barangay"] ?? null,
    "sitio" => $nearest["sitio"] ?? null,
    "street_address" => $nearest["street_address"] ?? null,
    "full_address" => $nearest["full_address"] ?? null,
    "contact_person" => $nearest["contact_person"] ?? null,
    "contact_position" => $nearest["contact_position"] ?? null,
    "contact_mobile" => $nearest["contact_mobile"] ?? null,
    "contact_landline" => $nearest["contact_landline"] ?? null,
    "contact_email" => $nearest["contact_email"] ?? null,
    "emergency_contact" => $nearest["emergency_contact"] ?? null,
    "operating_hours" => $nearest["operating_hours"] ?? null,
    "lat" => isset($nearest["lat"]) ? (float)$nearest["lat"] : null,
    "lng" => isset($nearest["lng"]) ? (float)$nearest["lng"] : null,
    "distance_m" => isset($nearest["distance_m"]) ? (int)$nearest["distance_m"] : null,
    "assignment_rule" => $assignmentRule
  ];
}

function find_nearest_station_anywhere(PDO $pdo, float $lat, float $lng): ?array {
  $stmt = $pdo->prepare("
    SELECT
      ps.*,
      ROUND(
        6371000 * 2 * ASIN(
          SQRT(
            POWER(SIN(RADIANS(ps.lat - ?) / 2), 2) +
            COS(RADIANS(?)) * COS(RADIANS(ps.lat)) *
            POWER(SIN(RADIANS(ps.lng - ?) / 2), 2)
          )
        )
      ) AS distance_m
    FROM police_stations ps
    WHERE ps.lat IS NOT NULL
      AND ps.lng IS NOT NULL
      AND ps.verification_status = 'approved'
      AND ps.is_active = 1
    ORDER BY distance_m ASC
    LIMIT 1
  ");

  $stmt->execute([$lat, $lat, $lng]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}

function find_scope_stations(PDO $pdo, ?string $province, ?string $city): array {

  if(!$province){
    return [];
  }

  if($city){
    $stmt=$pdo->prepare("
      SELECT
       id,
       station_name,
       station_type,
       lat,
       lng,
       city_municipality,
       province
      FROM police_stations
      WHERE verification_status='approved'
      AND is_active=1
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND LOWER(TRIM(province))=LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality))=LOWER(TRIM(?))
      ORDER BY station_name
    ");
    $stmt->execute([$province,$city]);
  } else {
    $stmt=$pdo->prepare("
      SELECT
       id,
       station_name,
       station_type,
       lat,
       lng,
       city_municipality,
       province
      FROM police_stations
      WHERE verification_status='approved'
      AND is_active=1
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND LOWER(TRIM(province))=LOWER(TRIM(?))
      ORDER BY station_name
    ");
    $stmt->execute([$province]);
  }

  return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$lat = $_GET["lat"] ?? null;
$lng = $_GET["lng"] ?? null;

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, [
    "ok" => false,
    "message" => "Invalid coordinates"
  ]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, [
    "ok" => false,
    "message" => "Coordinates out of range"
  ]);
}

try {
  $geo = reverse_geocode_scope($pdo, $lat, $lng);

  $region = null;
  $province = null;
  $cityMunicipality = null;
  $barangay = null;
  $geoMessage = null;

  if ($geo["ok"]) {
    $scope = $geo["address"] ?? [];

    $region = normalize_scope_value($scope["region"] ?? null);
    $province = normalize_scope_value($scope["province"] ?? null);
    $cityMunicipality = normalize_scope_value($scope["city_municipality"] ?? null);
    $barangay = normalize_scope_value($scope["barangay"] ?? null);
  } else {
    $geoMessage = $geo["message"] ?? "Reverse geocoding failed";
  }

  $nearest = null;
  $assignmentRule = "GPS_FALLBACK";

  if ($province && $cityMunicipality) {
    $nearest = assign_incident_station(
      $pdo,
      $lat,
      $lng,
      $province,
      $cityMunicipality,
      $barangay
    );

    $assignmentRule = $nearest["_assignment_rule"] ?? "AREA_OR_CITY_FIRST";
  }

  if (!$nearest) {
    $nearest = find_nearest_station_anywhere($pdo, $lat, $lng);
    $assignmentRule = "GPS_FALLBACK";
  }

  out(200, [
    "ok" => true,
    "scope" => [
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay,
      "geocoding_status" => $geo["ok"] ? "ok" : "failed",
      "geocoding_message" => $geoMessage
    ],
    "station" => $nearest ? format_station_response($nearest,$assignmentRule):null,
    "stations" => find_scope_stations(
      $pdo,
      $province,
      $cityMunicipality
    )
  ]);

} catch (Throwable $e) {
  $fallback = null;

  try {
    $fallback = find_nearest_station_anywhere($pdo, $lat, $lng);
  } catch (Throwable $ignored) {
    $fallback = null;
  }

  if ($fallback) {
    out(200, [
      "ok" => true,
      "scope" => [
        "region" => null,
        "province" => null,
        "city_municipality" => null,
        "barangay" => null,
        "geocoding_status" => "failed",
        "geocoding_message" => $e->getMessage()
      ],
      "station" => format_station_response($fallback, "GPS_FALLBACK")
    ]);
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}