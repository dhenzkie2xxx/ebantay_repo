<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function distance_meters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;

  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a =
    sin($dLat / 2) * sin($dLat / 2) +
    cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
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
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($user);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($user["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can detect nearest Police on Field."
    ]);
  }

  $stationId = (int)$user["station_id"];

  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.station_id,
      u.duty_status,
      u.last_seen_at,

      latest.lat,
      latest.lng,
      latest.accuracy_m,
      latest.created_at AS location_updated_at

    FROM users u

    INNER JOIN (
      SELECT ul1.*
      FROM user_locations ul1
      INNER JOIN (
        SELECT user_id, MAX(created_at) AS max_created_at
        FROM user_locations
        GROUP BY user_id
      ) ul2
        ON ul2.user_id = ul1.user_id
       AND ul2.max_created_at = ul1.created_at
    ) latest
      ON latest.user_id = u.id

    WHERE u.role = 'police_on_field'
      AND u.station_id = ?
      AND u.valid = 'valid'
      AND u.account_status = 'active'
      AND u.is_email_verified = 1
      AND u.account_flag_status <> 'suspended'
      AND u.duty_status = 'available'
      AND latest.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
  ");

  $stmt->execute([$stationId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDistance = null;

  foreach ($rows as $r) {
    if (!is_numeric($r["lat"]) || !is_numeric($r["lng"])) {
      continue;
    }

    $distance = distance_meters(
      $lat,
      $lng,
      (float)$r["lat"],
      (float)$r["lng"]
    );

    if ($nearestDistance === null || $distance < $nearestDistance) {
      $nearestDistance = $distance;
      $nearest = $r;
    }
  }

  if (!$nearest) {
    out(200, [
      "ok" => true,
      "message" => "No available Police on Field found.",
      "nearest_police" => null
    ]);
  }

  out(200, [
    "ok" => true,
    "message" => "Nearest available Police on Field found.",
    "nearest_police" => [
      "id" => (int)$nearest["id"],
      "firstname" => $nearest["firstname"],
      "lastname" => $nearest["lastname"],
      "full_name" => trim($nearest["firstname"] . " " . $nearest["lastname"]),
      "email" => $nearest["email"],
      "username" => $nearest["username"],
      "station_id" => (int)$nearest["station_id"],
      "duty_status" => $nearest["duty_status"],
      "last_seen_at" => $nearest["last_seen_at"],
      "distance_m" => (int)round($nearestDistance),
      "location" => [
        "lat" => (float)$nearest["lat"],
        "lng" => (float)$nearest["lng"],
        "accuracy_m" => $nearest["accuracy_m"] !== null ? (int)$nearest["accuracy_m"] : null,
        "updated_at" => $nearest["location_updated_at"]
      ]
    ]
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}