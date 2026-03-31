<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));
$scope = admin_scope_from_auth($pdo, $AUTH_USER);

$params = [":days" => $days];
$where = " WHERE COALESCE(date_reported, created_at) >= (UTC_TIMESTAMP() - INTERVAL :days DAY) ";
$where .= scope_where_clause("province", $scope, $params, ":scope_province");

$sql = "
  SELECT DATE(COALESCE(date_reported, created_at)) AS d, COUNT(*) AS c
  FROM incident_reports
  $where
  GROUP BY DATE(COALESCE(date_reported, created_at))
  ORDER BY d ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

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
  "scope" => $scope,
  "series" => $series
]);