<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$limit = (int)($_GET["limit"] ?? 5);
if ($limit < 1) $limit = 5;
if ($limit > 20) $limit = 20;

$sql = "
  SELECT
    id,
    name,
    radius_m,
    hotspot_type,
    risk_level,
    last_detected_at
  FROM crime_hotspots
  WHERE active = 1
  ORDER BY
    CASE risk_level
      WHEN 'HIGH' THEN 1
      WHEN 'MEDIUM' THEN 2
      WHEN 'LOW' THEN 3
      ELSE 4
    END,
    last_detected_at DESC,
    created_at DESC
  LIMIT $limit
";

$stmt = $pdo->query($sql);
$items = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $items[] = [
    "id" => (int)$row["id"],
    "name" => $row["name"],
    "radius_m" => (int)$row["radius_m"],
    "hotspot_type" => $row["hotspot_type"],
    "risk_level" => strtoupper((string)($row["risk_level"] ?? "UNKNOWN")),
    "last_detected_at" => $row["last_detected_at"]
    ? gmdate("Y-m-d H:i", strtotime($row["last_detected_at"]))
    : null
  ];
}

echo json_encode([
  "ok" => true,
  "items" => $items
]);