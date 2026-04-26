<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$token = bearer_token();

if ($token === "") {
  out(401, [
    "ok" => false,
    "message" => "Missing token"
  ]);
}

try {
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, [
      "ok" => false,
      "message" => "Unauthorized"
    ]);
  }

  if (auth_check_token_expired($user)) {
    out(401, [
      "ok" => false,
      "message" => "Token expired"
    ]);
  }

  $gate = auth_admin_station_gate($user);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($user["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can view Police on Field accounts."
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
      u.role,
      u.station_id,
      u.valid,
      u.account_status,
      u.account_flag_status,
      u.duty_status,
      u.last_seen_at,
      u.created_at,

      up.mobile_number,
      up.address_text,
      up.barangay,
      up.city_municipality,
      up.province,
      up.region,

      latest.lat,
      latest.lng,
      latest.accuracy_m,
      latest.created_at AS location_updated_at

    FROM users u

    LEFT JOIN user_profiles up
      ON up.user_id = u.id

    LEFT JOIN (
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

    ORDER BY
      FIELD(u.duty_status, 'available', 'enroute', 'on_scene', 'busy', 'offline'),
      u.lastname ASC,
      u.firstname ASC
  ");

  $stmt->execute([$stationId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $police = array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "firstname" => $r["firstname"],
      "lastname" => $r["lastname"],
      "full_name" => trim($r["firstname"] . " " . $r["lastname"]),
      "email" => $r["email"],
      "username" => $r["username"],
      "role" => $r["role"],
      "station_id" => $r["station_id"] !== null ? (int)$r["station_id"] : null,
      "valid" => $r["valid"],
      "account_status" => $r["account_status"],
      "account_flag_status" => $r["account_flag_status"],
      "duty_status" => $r["duty_status"] ?? "offline",
      "last_seen_at" => $r["last_seen_at"],
      "mobile_number" => $r["mobile_number"],
      "address_text" => $r["address_text"],
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "region" => $r["region"],
      "location" => [
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
        "updated_at" => $r["location_updated_at"]
      ],
      "created_at" => $r["created_at"]
    ];
  }, $rows);

  out(200, [
    "ok" => true,
    "station" => [
      "id" => $stationId,
      "name" => $user["station_name"] ?? null,
      "province" => $user["station_province"] ?? null,
      "city_municipality" => $user["station_city_municipality"] ?? null
    ],
    "count" => count($police),
    "police_on_field" => $police
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}