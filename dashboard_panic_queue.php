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

$limit = (int)($_GET["limit"] ?? 6);
if ($limit < 1) $limit = 6;
if ($limit > 20) $limit = 20;

$role = (string)($AUTH_USER["role"] ?? "");
$stationProvince = normalize_scope_value($AUTH_USER["station_province"] ?? null);

if ($role === "admin" && !$stationProvince) {
  out(403, [
    "ok" => false,
    "message" => "Admin station province is not configured."
  ]);
}

$sql = "
  SELECT
    p.id,
    p.level,
    p.status,
    p.created_at,
    p.region,
    p.province,
    p.city_municipality,
    p.barangay,
    p.assigned_station_id,
    u.firstname,
    u.lastname,
    u.email
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  WHERE p.status IN ('new', 'ack')
";
$params = [];

if ($role === "admin") {
  $sql .= " AND LOWER(TRIM(p.province)) = LOWER(TRIM(:station_province)) ";
  $params[":station_province"] = $stationProvince;
}

$sql .= "
  ORDER BY
    CASE p.level
      WHEN 'urgent' THEN 1
      WHEN 'alert' THEN 2
      ELSE 3
    END,
    p.created_at DESC
  LIMIT :limit
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
$stmt->execute();

$items = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $items[] = [
    "id" => (int)$row["id"],
    "level" => $row["level"],
    "status" => $row["status"],
    "created_at" => gmdate("Y-m-d H:i", strtotime($row["created_at"])),
    "region" => $row["region"],
    "province" => $row["province"],
    "city_municipality" => $row["city_municipality"],
    "barangay" => $row["barangay"],
    "assigned_station_id" => $row["assigned_station_id"] !== null ? (int)$row["assigned_station_id"] : null,
    "user_name" => trim(($row["firstname"] ?? "") . " " . ($row["lastname"] ?? "")),
    "email" => $row["email"]
  ];
}

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_province" => $role === "admin" ? $stationProvince : null,
    "is_global" => $role === "super_admin"
  ],
  "items" => $items
]);