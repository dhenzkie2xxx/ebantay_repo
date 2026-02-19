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

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
if ($days < 1) $days = 1;
if ($days > 365) $days = 365;

$category = trim($_GET["category"] ?? "");     // e.g. Theft
$group = isset($_GET["group"]) ? (int)$_GET["group"] : 0;

// bbox (optional)
$minLat = isset($_GET["minLat"]) ? (float)$_GET["minLat"] : null;
$maxLat = isset($_GET["maxLat"]) ? (float)$_GET["maxLat"] : null;
$minLng = isset($_GET["minLng"]) ? (float)$_GET["minLng"] : null;
$maxLng = isset($_GET["maxLng"]) ? (float)$_GET["maxLng"] : null;

$where = [];
$params = [];

$where[] = "status <> 'REJECTED'";
$where[] = "lat IS NOT NULL AND lng IS NOT NULL";
$where[] = "created_at >= (NOW() - INTERVAL ? DAY)";
$params[] = $days;

if ($category !== "") {
  $where[] = "category = ?";
  $params[] = $category;
}

if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
  $where[] = "lat BETWEEN ? AND ?";
  $where[] = "lng BETWEEN ? AND ?";
  array_push($params, $minLat, $maxLat, $minLng, $maxLng);
}

$sql = "
SELECT
  lat,
  lng,
  category,
  status,
  created_at
FROM incident_reports
WHERE " . implode(" AND ", $where);

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // weight: status + recency
  $computeWeight = function($status, $createdAt) {
    $w = 1;
    if ($status === "PENDING") $w = 3;
    else if ($status === "REVIEWED") $w = 2;
    else if ($status === "RESOLVED") $w = 1;
    else return 0;

    $ageSec = time() - strtotime($createdAt);
    if ($ageSec <= 86400) $w += 2;
    else if ($ageSec <= 7 * 86400) $w += 1;

    return $w;
  };

  if ($group === 1) {
    $grouped = []; // { "Theft": [points], "Assault": [points], ... }

    foreach ($rows as $r) {
      $w = $computeWeight($r["status"], $r["created_at"]);
      if ($w <= 0) continue;

      $cat = $r["category"] ?? "Unknown";
      if (!isset($grouped[$cat])) $grouped[$cat] = [];

      $grouped[$cat][] = [
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "weight" => $w,
      ];
    }

    out(200, [
      "ok" => true,
      "days" => $days,
      "category" => $category === "" ? "ALL" : $category,
      "grouped" => true,
      "data" => $grouped,
    ]);
  }

  // non-grouped (single list)
  $points = [];
  foreach ($rows as $r) {
    $w = $computeWeight($r["status"], $r["created_at"]);
    if ($w <= 0) continue;

    $points[] = [
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "weight" => $w,
      "category" => $r["category"],
    ];
  }

  out(200, [
    "ok" => true,
    "days" => $days,
    "category" => $category === "" ? "ALL" : $category,
    "grouped" => false,
    "count" => count($points),
    "points" => $points
  ]);

} catch (Throwable $e) {
  out(500, ["ok" => false, "message" => "Server error"]);
}
