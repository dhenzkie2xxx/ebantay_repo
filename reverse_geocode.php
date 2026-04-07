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

function looks_like_region_name(?string $value): bool {
  $value = strtolower(trim((string)$value));
  if ($value === "") return false;

  return
    str_contains($value, "region") ||
    str_contains($value, "national capital region") ||
    $value === "ncr" ||
    str_contains($value, "metro manila");
}

function resolve_scope_from_city(PDO $pdo, ?string $cityMunicipality): array {
  $cityMunicipality = normalize_scope_value($cityMunicipality);
  if (!$cityMunicipality) {
    return [
      "province" => null,
      "region" => null,
      "city_municipality" => null,
    ];
  }

  $sql = "
    SELECT
      c.canonical_name AS city_municipality,
      p.canonical_name AS province,
      r.canonical_name AS region
    FROM location_cities c
    INNER JOIN location_provinces p ON p.id = c.province_id
    INNER JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))

    UNION

    SELECT
      c.canonical_name AS city_municipality,
      p.canonical_name AS province,
      r.canonical_name AS region
    FROM location_city_aliases a
    INNER JOIN location_cities c ON c.id = a.city_id
    INNER JOIN location_provinces p ON p.id = c.province_id
    INNER JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(TRIM(a.alias_name)) = LOWER(TRIM(?))

    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$cityMunicipality, $cityMunicipality]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return [
    "province" => normalize_scope_value($row["province"] ?? null),
    "region" => normalize_scope_value($row["region"] ?? null),
    "city_municipality" => normalize_scope_value($row["city_municipality"] ?? $cityMunicipality),
  ];
}

function reverse_geocode_scope(PDO $pdo, float $lat, float $lng): array {
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
    if (looks_like_region_name($state)) {
      if ($region === "") $region = $state;
    } else {
      $province = $state;
    }
  }

  // Fallback from your own location tables when province is still missing.
  if ($province === "" && $cityMunicipality !== "") {
    $resolved = resolve_scope_from_city($pdo, $cityMunicipality);

    if (!empty($resolved["city_municipality"])) {
      $cityMunicipality = $resolved["city_municipality"];
    }
    if ($province === "" && !empty($resolved["province"])) {
      $province = $resolved["province"];
    }
    if ($region === "" && !empty($resolved["region"])) {
      $region = $resolved["region"];
    }
  }

  $placeOfIncident = $addr["road"]
    ?? $addr["amenity"]
    ?? $addr["building"]
    ?? $addr["tourism"]
    ?? $addr["shop"]
    ?? "";

  $displayName = $json["display_name"] ?? "";

  return [
    "ok" => true,
    "address" => [
      "barangay" => normalize_scope_value($barangay),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region),
      "place_of_incident" => normalize_scope_value($placeOfIncident),
      "display_name" => normalize_scope_value($displayName)
    ]
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
  $result = reverse_geocode_scope($pdo, $lat, $lng);

  if (!$result["ok"]) {
    out(502, [
      "ok" => false,
      "message" => $result["message"]
    ]);
  }

  out(200, $result);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}