<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
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

  $barangay = $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? "";

  $cityMunicipality = $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? $addr["county"]
    ?? "";

  $province = $addr["state"]
    ?? $addr["province"]
    ?? $addr["region"]
    ?? "";

  $region = $addr["region"]
    ?? $addr["state_district"]
    ?? "";

  $road = $addr["road"] ?? "";
  $displayName = $json["display_name"] ?? "";

  out(200, [
    "ok" => true,
    "address" => [
      "barangay" => $barangay,
      "city_municipality" => $cityMunicipality,
      "province" => $province,
      "region" => $region,
      "place_of_incident" => $road,
      "display_name" => $displayName
    ]
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}