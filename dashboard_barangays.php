<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$limit = (int)($_GET["limit"] ?? 7);
if ($limit < 1) $limit = 7;
if ($limit > 20) $limit = 20;

$sql = "
  SELECT barangay, COUNT(*) AS total
  FROM incident_reports
  WHERE verification_status = 'VERIFIED'
    AND barangay IS NOT NULL
    AND barangay <> ''
  GROUP BY barangay
  ORDER BY total DESC, barangay ASC
  LIMIT $limit
";

$stmt = $pdo->query($sql);
$items = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $items[] = [
    "barangay" => $row["barangay"],
    "count" => (int)$row["total"]
  ];
}

echo json_encode([
  "ok" => true,
  "items" => $items
]);