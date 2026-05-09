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

function http_get_json(string $url): array {
  $headers = [
    "User-Agent: eBantay/1.0",
    "Accept: application/json",
  ];

  // Try cURL first
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw !== false && $status >= 200 && $status < 300) {
      $json = json_decode($raw, true);
      if (is_array($json)) {
        return ["ok" => true, "json" => $json];
      }
      return ["ok" => false, "message" => "Invalid geocoding JSON response"];
    }

    $msg = $err ?: ("HTTP " . $status);
    return ["ok" => false, "message" => "Geocoding request failed: " . $msg];
  }

  // Fallback to file_get_contents if cURL is unavailable
  $opts = [
    "http" => [
      "method" => "GET",
      "header" => "User-Agent: eBantay/1.0\r\nAccept: application/json\r\n",
      "timeout" => 20
    ],
    "ssl" => [
      "verify_peer" => true,
      "verify_peer_name" => true,
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

  $barangayCanonical = null;

if ($province !== "" && $cityMunicipality !== "" && $barangay !== "") {
  require_once __DIR__ . "/location_resolver.php";

  $scope = canonicalize_scope($pdo, $region, $province, $cityMunicipality);

  if (!empty($scope["ok"])) {
    $province = $scope["province"];
    $cityMunicipality = $scope["city_municipality"];
    $region = $scope["region"] ?? $region;

    $barangayCanonical = resolve_barangay(
      $pdo,
      $province,
      $cityMunicipality,
      $barangay
    );
  }
}

return [
  "ok" => true,
  "address" => [
    "barangay" => normalize_scope_value($barangayCanonical ?: $barangay),
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