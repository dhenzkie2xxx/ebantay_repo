<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

$days = max(7, min(90, (int)($_GET["days"] ?? 30)));

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$where = " WHERE COALESCE(date_reported, created_at) >= (UTC_TIMESTAMP() - INTERVAL :days DAY) ";
$params = [
  ":days" => $days
];

if ($role === "admin") {
  $where .= " AND LOWER(TRIM(province)) = LOWER(TRIM(:station_province)) ";
  $params[":station_province"] = $stationProvince;
}

$sql = "
  SELECT DATE(COALESCE(date_reported, created_at)) AS d, COUNT(*) AS c
  FROM incident_reports
  {$where}
  GROUP BY DATE(COALESCE(date_reported, created_at))
  ORDER BY d ASC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  if ($k === ":days") {
    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $stmt->bindValue($k, $v);
  }
}
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
  "scope" => [
    "role" => $role,
    "station_province" => $role === "admin" ? $stationProvince : null,
    "is_global" => $role === "super_admin"
  ],
  "series" => $series
]);