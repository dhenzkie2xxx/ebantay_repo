<?php
require_once __DIR__ . "/db.php";

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

function haversineMeters($lat1, $lng1, $lat2, $lng2): float {
  $earth = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a = sin($dLat / 2) * sin($dLat / 2)
     + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
     * sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
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
    return [
      "ok" => false,
      "message" => "Reverse geocoding service unavailable"
    ];
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    return [
      "ok" => false,
      "message" => "Invalid geocoding response"
    ];
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
  $region = $addr["region"]
    ?? $addr["state_district"]
    ?? "";

  $province = $addr["province"]
    ?? $addr["county"]
    ?? "";

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

function find_nearest_station_in_province(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  if (!$province) return null;

  $stmt = $pdo->prepare("
    SELECT
      id,
      station_name,
      station_code,
      station_type,
      region,
      province,
      city_municipality,
      barangay,
      sitio,
      street_address,
      full_address,
      contact_person,
      contact_position,
      contact_mobile,
      contact_landline,
      contact_email,
      emergency_contact,
      operating_hours,
      lat,
      lng
    FROM police_stations
    WHERE verification_status = 'approved'
      AND is_active = 1
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
  ");
  $stmt->execute([$province]);
  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$stations) return null;

  $nearest = null;
  $nearestDistance = null;

  foreach ($stations as $station) {
    $d = haversineMeters($lat, $lng, (float)$station["lat"], (float)$station["lng"]);
    if ($nearestDistance === null || $d < $nearestDistance) {
      $nearestDistance = $d;
      $nearest = $station;
    }
  }

  if (!$nearest) return null;

  $nearest["distance_m"] = (int)round($nearestDistance);
  return $nearest;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$lat = $_GET["lat"] ?? null;
$lng = $_GET["lng"] ?? null;

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, ["ok" => false, "message" => "Coordinates out of range"]);
}

try {
  $geo = reverse_geocode_scope($lat, $lng);
  if (!$geo["ok"]) {
    out(502, [
      "ok" => false,
      "message" => $geo["message"]
    ]);
  }

  $scope = $geo["address"];
  $province = $scope["province"] ?? null;

  if (!$province) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine province from current location"
    ]);
  }

  $nearest = find_nearest_station_in_province($pdo, $lat, $lng, $province);

  out(200, [
    "ok" => true,
    "scope" => [
      "region" => $scope["region"] ?? null,
      "province" => $scope["province"] ?? null,
      "city_municipality" => $scope["city_municipality"] ?? null,
      "barangay" => $scope["barangay"] ?? null,
    ],
    "station" => $nearest ? [
      "id" => (int)$nearest["id"],
      "station_name" => $nearest["station_name"],
      "station_code" => $nearest["station_code"],
      "station_type" => $nearest["station_type"],
      "region" => $nearest["region"],
      "province" => $nearest["province"],
      "city_municipality" => $nearest["city_municipality"],
      "barangay" => $nearest["barangay"],
      "sitio" => $nearest["sitio"],
      "street_address" => $nearest["street_address"],
      "full_address" => $nearest["full_address"],
      "contact_person" => $nearest["contact_person"],
      "contact_position" => $nearest["contact_position"],
      "contact_mobile" => $nearest["contact_mobile"],
      "contact_landline" => $nearest["contact_landline"],
      "contact_email" => $nearest["contact_email"],
      "emergency_contact" => $nearest["emergency_contact"],
      "operating_hours" => $nearest["operating_hours"],
      "lat" => (float)$nearest["lat"],
      "lng" => (float)$nearest["lng"],
      "distance_m" => (int)$nearest["distance_m"]
    ] : null
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}