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

$status = strtolower(trim($_GET["status"] ?? "new"));
$allowed = ["all", "new", "ack", "resolved"];
if (!in_array($status, $allowed, true)) $status = "new";

$limit = (int)($_GET["limit"] ?? 80);
if ($limit < 1) $limit = 80;
if ($limit > 200) $limit = 200;

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$whereParts = [];
$params = [];

if ($status !== "all") {
  $whereParts[] = "p.status = :status";
  $params[":status"] = $status;
}

if ($role === "admin") {
  $whereParts[] = "LOWER(TRIM(p.province)) = LOWER(TRIM(:station_province))";
  $params[":station_province"] = $stationProvince;
}

$whereSql = "";
if (!empty($whereParts)) {
  $whereSql = "WHERE " . implode(" AND ", $whereParts);
}

$orderSql = "ORDER BY p.created_at DESC";
if ($role === "admin" && !empty($AUTH_USER["station_id"])) {
  $orderSql = "
    ORDER BY
      CASE
        WHEN p.assigned_station_id = :auth_station_id THEN 0
        ELSE 1
      END,
      p.created_at DESC
  ";
  $params[":auth_station_id"] = (int)$AUTH_USER["station_id"];
}

$sql = "
  SELECT
    p.id,
    p.level,
    p.lat,
    p.lng,
    p.accuracy_m,
    p.region,
    p.province,
    p.city_municipality,
    p.barangay,
    p.assigned_station_id,
    p.device_time,
    p.created_at,
    p.status,
    u.firstname,
    u.lastname,
    u.email,
    ps.station_name AS assigned_station_name,
    ps.station_code AS assigned_station_code,
    ps.station_type AS assigned_station_type,
    ps.full_address AS assigned_station_address
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  LEFT JOIN police_stations ps ON ps.id = p.assigned_station_id
  $whereSql
  $orderSql
  LIMIT :limit
";

$stmt = $pdo->prepare($sql);

foreach ($params as $k => $v) {
  if ($k === ":auth_station_id") {
    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $stmt->bindValue($k, $v);
  }
}
$stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "status" => $status,
  "scope" => [
    "role" => $role,
    "station_province" => $role === "admin" ? $stationProvince : null,
    "is_global" => $role === "super_admin"
  ],
  "panic" => array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "level" => $r["level"],
      "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
      "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
      "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
      "region" => $r["region"],
      "province" => $r["province"],
      "city_municipality" => $r["city_municipality"],
      "barangay" => $r["barangay"],
      "device_time" => $r["device_time"],
      "created_at" => $r["created_at"],
      "status" => $r["status"],
      "assigned_station" => [
        "id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
        "station_name" => $r["assigned_station_name"],
        "station_code" => $r["assigned_station_code"],
        "station_type" => $r["assigned_station_type"],
        "full_address" => $r["assigned_station_address"]
      ],
      "user" => [
        "name" => trim(($r["firstname"] ?? "") . " " . ($r["lastname"] ?? "")),
        "email" => $r["email"]
      ]
    ];
  }, $rows)
]);