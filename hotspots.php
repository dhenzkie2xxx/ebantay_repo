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

try {
  $q = $pdo->query("
    SELECT id, name, lat, lng, radius_m
    FROM crime_hotspots
    WHERE active = 1
    ORDER BY id DESC
  ");

  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  out(200, [
    "ok" => true,
    "hotspots" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "name" => $r["name"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "radius_m" => (int)$r["radius_m"],
      ];
    }, $rows)
  ]);
} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}
