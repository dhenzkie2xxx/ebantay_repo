<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $limit = (int)($_GET["limit"] ?? 5);
  if ($limit < 1) $limit = 5;
  if ($limit > 20) $limit = 20;

  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $params = [];
  $where = " WHERE active = 1 ";
  $where .= scope_where_clause("province", $scope, $params, ":scope_province");
  $where .= scope_city_where_clause("city_municipality", $scope, $params, ":scope_city");

  $sql = "
    SELECT
      id,
      name,
      region,
      province,
      city_municipality,
      barangay,
      lat,
      lng,
      radius_m,
      hotspot_type,
      risk_level,
      last_detected_at,
      created_at
    FROM crime_hotspots
    $where
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

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$row["id"],
      "name" => $row["name"] ?? null,
      "region" => $row["region"] ?? null,
      "province" => $row["province"] ?? null,
      "city_municipality" => $row["city_municipality"] ?? null,
      "barangay" => $row["barangay"] ?? null,
      "lat" => $row["lat"] !== null ? (float)$row["lat"] : null,
      "lng" => $row["lng"] !== null ? (float)$row["lng"] : null,
      "radius_m" => isset($row["radius_m"]) ? (int)$row["radius_m"] : 0,
      "hotspot_type" => $row["hotspot_type"] ?? null,
      "risk_level" => strtoupper((string)($row["risk_level"] ?? "UNKNOWN")),
      "last_detected_at" => $row["last_detected_at"] ?? null,
      "created_at" => $row["created_at"] ?? null
    ];
  }

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "items" => $items
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage(),
    "file" => basename(__FILE__),
    "line" => $e->getLine()
  ]);
}