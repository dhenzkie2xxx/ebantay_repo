<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function clean_text($value): string {
  return trim((string)$value);
}

function normalize_ph_address(array $addr): array {
  $countryCode = strtolower(clean_text($addr["country_code"] ?? ""));
  $isPhilippines = $countryCode === "ph";

  $barangay = clean_text(
    $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? ""
  );

  $cityMunicipality = clean_text(
    $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? ""
  );

  $region = "";
  $province = "";

  if ($isPhilippines) {
    /*
    |--------------------------------------------------------------------------
    | PHILIPPINES-SPECIFIC FIELD PRIORITY
    |--------------------------------------------------------------------------
    | Nominatim often returns:
    | - state => REGION (e.g. Northern Mindanao)
    | - county / province => ACTUAL PROVINCE (e.g. Bukidnon)
    */
    $province = clean_text(
      $addr["province"]
      ?? $addr["county"]
      ?? $addr["state_district"]
      ?? ""
    );

    $region = clean_text(
      $addr["region"]
      ?? $addr["state"]
      ?? ""
    );

    /*
    |--------------------------------------------------------------------------
    | Fallback city handling
    |--------------------------------------------------------------------------
    | In some PH responses, city may hide under village/town/municipality.
    */
    if ($cityMunicipality === "") {
      $cityMunicipality = clean_text(
        $addr["municipality"]
        ?? $addr["town"]
        ?? $addr["city_district"]
        ?? ""
      );
    }
  } else {
    $province = clean_text(
      $addr["province"]
      ?? $addr["state"]
      ?? $addr["county"]
      ?? ""
    );

    $region = clean_text(
      $addr["region"]
      ?? $addr["state_district"]
      ?? ""
    );
  }

  return [
    "barangay" => $barangay,
    "city_municipality" => $cityMunicipality,
    "province" => $province,
    "region" => $region
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
    out(502, ["ok" => false, "message" => "Reverse geocoding service unavailable"]);
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    out(502, ["ok" => false, "message" => "Invalid geocoding response"]);
  }

  $addr = $json["address"] ?? [];
  $normalized = normalize_ph_address($addr);

  $road = clean_text($addr["road"] ?? "");
  $displayName = clean_text($json["display_name"] ?? "");

  out(200, [
    "ok" => true,
    "address" => [
      "barangay" => $normalized["barangay"],
      "city_municipality" => $normalized["city_municipality"],
      "province" => $normalized["province"],
      "region" => $normalized["region"],
      "place_of_incident" => $road,
      "display_name" => $displayName
    ],
    "raw_address" => $addr
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}