<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$days7 = 7;
$days30 = 30;

// Haversine distance in meters
// distance = 6371000 * acos( cos(lat1)*cos(lat2)*cos(lng2-lng1)+sin(lat1)*sin(lat2) )
$sql = "
  SELECT
    h.id,
    h.name,
    h.lat,
    h.lng,
    h.radius_m,

    SUM(
      CASE WHEN r.created_at >= (UTC_TIMESTAMP() - INTERVAL :d7 DAY) THEN 1 ELSE 0 END
    ) AS last7,

    SUM(
      CASE WHEN r.created_at >= (UTC_TIMESTAMP() - INTERVAL :d30 DAY) THEN 1 ELSE 0 END
    ) AS last30

  FROM crime_hotspots h
  LEFT JOIN incident_reports r
    ON (
      6371000 * ACOS(
        COS(RADIANS(h.lat)) * COS(RADIANS(r.lat)) *
        COS(RADIANS(r.lng) - RADIANS(h.lng)) +
        SIN(RADIANS(h.lat)) * SIN(RADIANS(r.lat))
      )
    ) <= h.radius_m

  WHERE h.active = 1
  GROUP BY h.id
  ORDER BY h.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ":d7" => $days7,
  ":d30" => $days30
]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$predicted = array_map(function($r) {
  $last7 = (int)($r["last7"] ?? 0);
  $last30 = (int)($r["last30"] ?? 0);

  // Simple baseline score (capstone-defensible)
  $score = ($last7 * 0.6) + ($last30 * 0.4);

  return [
    "id" => (int)$r["id"],
    "name" => $r["name"],
    "lat" => (float)$r["lat"],
    "lng" => (float)$r["lng"],
    "radius_m" => (int)$r["radius_m"],
    "last7" => $last7,
    "last30" => $last30,
    "score" => $score
  ];
}, $rows);

// Sort by score desc
usort($predicted, fn($a,$b) => $b["score"] <=> $a["score"]);

out(200, ["ok"=>true, "predictions"=>$predicted]);