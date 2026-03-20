<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$sql = "
  SELECT DATE(COALESCE(date_reported, created_at)) AS d, COUNT(*) AS c
  FROM incident_reports
  WHERE COALESCE(date_reported, created_at) >= (UTC_TIMESTAMP() - INTERVAL :days DAY)
  GROUP BY DATE(COALESCE(date_reported, created_at))
  ORDER BY d ASC
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(":days", $days, PDO::PARAM_INT);
$stmt->execute();

$raw = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $raw[$row["d"]] = (int)$row["c"];
}

$series = [];
$start = new DateTimeImmutable("-" . ($days - 1) . " days", new DateTimeZone("UTC"));
$end = new DateTimeImmutable("now", new DateTimeZone("UTC"));

for ($d = $start; $d <= $end; $d = $d->modify("+1 day")) {
  $key = $d->format("Y-m-d");
  $series[] = [
    "date" => $key,
    "count" => $raw[$key] ?? 0
  ];
}

echo json_encode([
  "ok" => true,
  "series" => $series
]);