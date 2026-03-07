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
  $stmt = $pdo->query("
    SELECT
      id,
      name,
      lat,
      lng,
      radius_m,
      hotspot_type,
      risk_level,
      last_detected_at,
      created_at
    FROM crime_hotspots
    WHERE active = 1
    ORDER BY
      CASE
        WHEN risk_level = 'HIGH' THEN 1
        WHEN risk_level = 'MEDIUM' THEN 2
        WHEN risk_level = 'LOW' THEN 3
        ELSE 4
      END,
      id DESC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $hotspots = array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "name" => $r["name"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "radius_m" => (int)$r["radius_m"],
      "hotspot_type" => $r["hotspot_type"],
      "risk_level" => $r["risk_level"],
      "last_detected_at" => $r["last_detected_at"],
      "created_at" => $r["created_at"],
    ];
  }, $rows);

  out(200, [
    "ok" => true,
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