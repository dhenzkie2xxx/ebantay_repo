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

  $scope = $geo["address"] ?? [];

  $canon = canonicalize_scope_from_parts(
    $pdo,
    $scope["region"] ?? null,
    $scope["province"] ?? null,
    $scope["city_municipality"] ?? null
  );

  $region = $canon["region"] ?? normalize_scope_value($scope["region"] ?? null);
  $province = $canon["province"] ?? normalize_scope_value($scope["province"] ?? null);
  $cityMunicipality = $canon["city_municipality"] ?? normalize_scope_value($scope["city_municipality"] ?? null);
  $barangay = normalize_scope_value($scope["barangay"] ?? null);

  if (!$province) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine province from current location"
    ]);
  }

  if (!$cityMunicipality) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine city/municipality from current location"
    ]);
  }

  $nearest = find_nearest_station_in_city($pdo, $lat, $lng, $province, $cityMunicipality);
  $assignmentRule = "CITY_FIRST";

  if (!$nearest) {
    $nearest = find_nearest_station_in_province($pdo, $lat, $lng, $province);
    $assignmentRule = "PROVINCE_FALLBACK";
  }

  out(200, [
    "ok" => true,
    "scope" => [
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay,
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
      "distance_m" => (int)$nearest["distance_m"],
      "assignment_rule" => $assignmentRule
    ] : null
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}