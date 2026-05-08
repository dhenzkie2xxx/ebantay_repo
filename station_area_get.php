<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/station_area_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
if ($token === "") {
  $token = trim($_GET["token"] ?? "");
}

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

try {
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, ["ok" => false, "message" => "Only Station Admin can manage area of responsibility."]);
  }

  $stationId = (int)$admin["station_id"];

  if ($stationId <= 0) {
    out(403, ["ok" => false, "message" => "Admin station is not configured."]);
  }

  $stationStmt = $pdo->prepare("
    SELECT
      id,
      station_name,
      province,
      city_municipality,
      barangay
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stationStmt->execute([$stationId]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $areas = station_area_get_barangays($pdo, $stationId);

  out(200, [
    "ok" => true,
    "station" => [
      "id" => (int)$station["id"],
      "station_name" => $station["station_name"],
      "province" => $station["province"],
      "city_municipality" => $station["city_municipality"],
      "barangay" => $station["barangay"]
    ],
    "areas" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "station_id" => (int)$r["station_id"],
        "province" => $r["province"],
        "city_municipality" => $r["city_municipality"],
        "barangay" => $r["barangay"],
        "created_at" => $r["created_at"]
      ];
    }, $areas),
    "mode" => count($areas) > 0 ? "barangay_specific" : "whole_city"
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error.",
    "debug" => $e->getMessage()
  ]);
}