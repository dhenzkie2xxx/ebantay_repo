<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/verify.php";
header("Content-Type: application/json; charset=utf-8");

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$sql = "
  SELECT DATE(created_at) d, COUNT(*) c
  FROM incident_reports
  WHERE created_at >= (UTC_DATE() - INTERVAL $days DAY)
  GROUP BY DATE(created_at)
  ORDER BY d ASC
";

$res = $conn->query($sql);
$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = ["date" => $row["d"], "count" => (int)$row["c"]];
}
echo json_encode(["days" => $days, "series" => $out]);