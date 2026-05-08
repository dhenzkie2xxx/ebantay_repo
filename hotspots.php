<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/location_resolver.php";
require_once __DIR__ . "/hotspot_lib.php";

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

  $state = trim((string)($addr["state"] ?? ""));
  $region = $addr["region"] ?? $addr["state_district"] ?? "";
  $province = $addr["province"] ?? $addr["county"] ?? "";

  $cityMunicipality = $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? "";

  $barangay = $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? $addr["city_district"]
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
      "region" => normalize_scope_value($region),
      "province" => normalize_scope_value($province),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "barangay" => normalize_scope_value($barangay),
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

      if ($role === "admin" || $role === "police_on_field") {
        $canon = canonicalize_scope_from_parts(
          $pdo,
          $user["station_region"] ?? null,
          $user["station_province"] ?? null,
          $user["station_city_municipality"] ?? null
        );

        return [
          "source" => "auth",
          "role" => $role,
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

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
$days = max(1, min(365, $days));

try {
  $scope = resolve_request_scope($pdo);

  $role = strtolower((string)($scope["role"] ?? "public"));
  if ($role === "citizen") {
    out(200, [
      "ok" => true,
      "hotspots" => [],
      "scope" => [
        "source" => $scope["source"] ?? null,
        "role" => $role,
        "region" => $scope["region"] ?? null,
        "province" => $scope["province"] ?? null,
        "city_municipality" => $scope["city_municipality"] ?? null
      ]
    ]);
  }

  $role = (string)($scope["role"] ?? "public");
  $userId = isset($scope["user_id"]) ? (int)$scope["user_id"] : null;
  $regionFilter = normalize_scope_value($scope["region"] ?? null);
  $provinceFilter = normalize_scope_value($scope["province"] ?? null);
  $cityFilter = normalize_scope_value($scope["city_municipality"] ?? null);

  $hotspots = get_computed_hotspots(
    $pdo,
    $days,
    $provinceFilter,
    $cityFilter,
    $role,
    $userId
  );

  out(200, [
    "ok" => true,
    "days" => $days,
    "scope" => [
      "source" => $scope["source"] ?? "none",
      "role" => $role,
      "user_id" => $userId,
      "region" => $regionFilter,
      "province" => $provinceFilter,
      "city_municipality" => $cityFilter,
    ],
    "hotspots" => $hotspots,
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}