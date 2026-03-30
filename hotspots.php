<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
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
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region)
    ]
  ];
}

function resolve_request_scope(PDO $pdo): array {
  $token = bearer_token();
  if ($token !== "") {
    $user = auth_get_user_by_token($pdo, $token);
    if ($user && !auth_check_token_expired($user) && (($user["role"] ?? "") === "admin" || ($user["role"] ?? "") === "super_admin")) {
      return [
        "source" => "auth",
        "role" => $user["role"],
        "province" => ($user["role"] ?? "") === "admin"
          ? normalize_scope_value($user["station_province"] ?? null)
          : null
      ];
    }
  }

  $province = normalize_scope_value($_GET["province"] ?? null);
  if ($province) {
    return [
      "source" => "query",
      "role" => "public",
      "province" => $province
    ];
  }

  $lat = $_GET["lat"] ?? null;
  $lng = $_GET["lng"] ?? null;
  if (is_numeric($lat) && is_numeric($lng)) {
    $geo = reverse_geocode_scope((float)$lat, (float)$lng);
    if ($geo["ok"]) {
      return [
        "source" => "reverse_geocode",
        "role" => "public",
        "province" => normalize_scope_value($geo["address"]["province"] ?? null)
      ];
    }
  }

  return [
    "source" => "none",
    "role" => "public",
    "province" => null
  ];
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
$days = max(1, min(365, $days));

$scope = resolve_request_scope($pdo);
$provinceFilter = normalize_scope_value($scope["province"] ?? null);

try {
  $hotspots = get_computed_hotspots($pdo, $days, $provinceFilter);

  out(200, [
    "ok" => true,
    "days" => $days,
    "scope" => [
      "source" => $scope["source"],
      "role" => $scope["role"],
      "province" => $provinceFilter
    ],
    "count" => count($hotspots),
    "hotspots" => $hotspots
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}