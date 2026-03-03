<?php
require_once __DIR__ . "/require_admin.php";

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$sql = "
  SELECT DATE(created_at) d, COUNT(*) c
  FROM incident_reports
  WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL $days DAY)
  GROUP BY DATE(created_at)
  ORDER BY d ASC
";

$stmt = $pdo->query($sql);

$data = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $data[] = [
    "date" => $row["d"],
    "count" => (int)$row["c"]
  ];
}

echo json_encode([
  "ok" => true,
  "series" => $data
]);