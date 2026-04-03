<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/location_resolver.php";
require_once __DIR__ . "/station_assignment_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_text($value): string {
  return trim((string)($value ?? ""));
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function parse_device_time_to_sql($value): ?string {
  $value = trim((string)$value);
  if ($value === "") return null;

  $ts = strtotime($value);
  if ($ts === false) return null;

  return date("Y-m-d H:i:s", $ts);
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

  $road = $addr["road"] ?? "";
  $displayName = $json["display_name"] ?? "";

  return [
    "ok" => true,
    "address" => [
      "barangay" => normalize_scope_value($barangay),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region),
      "place_of_incident" => normalize_scope_value($road),
      "display_name" => normalize_scope_value($displayName)
    ]
  ];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

$token = normalize_text($data["token"] ?? "");
$level = normalize_text($data["level"] ?? "");
$lat = $data["lat"] ?? null;
$lng = $data["lng"] ?? null;
$accuracy = $data["accuracy"] ?? null;
$deviceTime = normalize_text($data["device_time"] ?? "");

if ($token === "" || !in_array($level, ["alert", "urgent"], true) || $lat === null || $lng === null) {
  out(400, ["ok" => false, "message" => "Missing/invalid fields"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, ["ok" => false, "message" => "Coordinates out of range"]);
}

try {
  $user = auth_get_user_by_token($pdo, $token);
  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if ((int)($user["is_email_verified"] ?? 0) !== 1) {
    out(403, ["ok" => false, "message" => "Email not verified"]);
  }

  if (($user["valid"] ?? "") !== "valid") {
    out(403, [
      "ok" => false,
      "message" => "Your account is not yet activated. Please complete account setup or contact the administrator."
    ]);
  }

  $deviceTimeSql = parse_device_time_to_sql($deviceTime);
  $accuracySql = ($accuracy !== null && is_numeric($accuracy)) ? (int)round((float)$accuracy) : null;

  $geo = reverse_geocode_scope($lat, $lng);
  if (!$geo["ok"]) {
    out(502, [
      "ok" => false,
      "message" => "Unable to resolve location scope for this panic request"
    ]);
  }

  $resolved = $geo["address"];
  $region = $resolved["region"] ?? null;
  $province = $resolved["province"] ?? null;
  $cityMunicipality = $resolved["city_municipality"] ?? null;
  $barangay = $resolved["barangay"] ?? null;

  if (!$province || !$cityMunicipality) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine the province and city/municipality from the current location"
    ]);
  }

  $canon = canonicalize_scope($pdo, $region, $province, $cityMunicipality);
  if (!$canon["ok"]) {
    out(422, [
      "ok" => false,
      "message" => $canon["message"]
    ]);
  }

  $region = $canon["region"];
  $province = $canon["province"];
  $cityMunicipality = $canon["city_municipality"];

  $assignedStation = assign_panic_station($pdo, $lat, $lng, $province);
  $assignedStationId = $assignedStation ? (int)$assignedStation["id"] : null;
  $assignmentRule = $assignedStation["_assignment_rule"] ?? "PROVINCE_NEAREST";

  $stmt = $pdo->prepare("
    INSERT INTO panic_requests (
      user_id,
      level,
      lat,
      lng,
      accuracy_m,
      region,
      province,
      city_municipality,
      barangay,
      assigned_station_id,
      device_time
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stmt->execute([
    (int)$user["id"],
    $level,
    $lat,
    $lng,
    $accuracySql,
    $region,
    $province,
    $cityMunicipality,
    $barangay,
    $assignedStationId,
    $deviceTimeSql
  ]);

  out(200, [
    "ok" => true,
    "message" => "Panic received",
    "id" => (int)$pdo->lastInsertId(),
    "level" => $level,
    "scope" => [
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay
    ],
    "assignment" => [
      "rule" => $assignmentRule,
      "assigned_station_id" => $assignedStationId
    ],
    "assigned_station" => $assignedStation ? [
      "id" => (int)$assignedStation["id"],
      "station_name" => $assignedStation["station_name"] ?? null,
      "station_code" => $assignedStation["station_code"] ?? null,
      "station_type" => $assignedStation["station_type"] ?? null,
      "province" => $assignedStation["province"] ?? null,
      "city_municipality" => $assignedStation["city_municipality"] ?? null,
      "barangay" => $assignedStation["barangay"] ?? null,
      "full_address" => $assignedStation["full_address"] ?? null,
      "distance_m" => isset($assignedStation["distance_m"]) ? (int)$assignedStation["distance_m"] : null
    ] : null
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}