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
      CURLOPT_TIMEOUT => 20,
      CURLOPT_CONNECTTIMEOUT => 10,
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
        return [
          "ok" => true,
          "json" => $json
        ];
      }

      return [
        "ok" => false,
        "message" => "Invalid geocoding JSON response"
      ];
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
      "timeout" => 20
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
    return [
      "ok" => false,
      "message" => "Invalid geocoding JSON response"
    ];
  }

  return [
    "ok" => true,
    "json" => $json
  ];
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

  /*
    Important fallback:
    Some areas such as Cagayan de Oro may return city but no clean province.
    This resolves:
    Cagayan de Oro -> Misamis Oriental -> Northern Mindanao
  */
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

  if (!$geo["ok"]) {
    out(502, [
      "ok" => false,
      "message" => $geo["message"]
    ]);
  }

  $scope = $geo["address"] ?? [];

  $region = normalize_scope_value($scope["region"] ?? null);
  $province = normalize_scope_value($scope["province"] ?? null);
  $cityMunicipality = normalize_scope_value($scope["city_municipality"] ?? null);
  $barangay = normalize_scope_value($scope["barangay"] ?? null);

  if (!$province || !$cityMunicipality) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine province/city from current location",
      "debug_scope" => [
        "region" => $region,
        "province" => $province,
        "city_municipality" => $cityMunicipality,
        "barangay" => $barangay
      ]
    ]);
  }

  $nearest = find_nearest_station_in_city(
    $pdo,
    $lat,
    $lng,
    $province,
    $cityMunicipality
  );

  $assignmentRule = "CITY_FIRST";

  if (!$nearest) {
    $nearest = find_nearest_station_in_province(
      $pdo,
      $lat,
      $lng,
      $province
    );

    $assignmentRule = "PROVINCE_FALLBACK";
  }

  out(200, [
    "ok" => true,
    "scope" => [
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay
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