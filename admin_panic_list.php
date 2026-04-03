<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $status = strtolower(trim($_GET["status"] ?? "new"));
  $allowed = ["all", "new", "ack", "resolved"];
  if (!in_array($status, $allowed, true)) $status = "new";

  $limit = (int)($_GET["limit"] ?? 80);
  if ($limit < 1) $limit = 80;
  if ($limit > 200) $limit = 200;

  $stationId = isset($scope["station_id"]) ? (int)$scope["station_id"] : 0;
  if (empty($scope["is_global"]) && $stationId <= 0) {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "message" => "Admin station is not configured."
    ]);
    exit;
  }

  $where = " WHERE 1=1 ";
  $params = [];

  if (empty($scope["is_global"])) {
    $where .= " AND p.assigned_station_id = :station_id ";
    $params[":station_id"] = $stationId;
  }

  if ($status !== "all") {
    $where .= " AND p.status = :status ";
    $params[":status"] = $status;
  }

  $stmt = $pdo->prepare("
    SELECT
      p.id,
      p.level,
      p.lat,
      p.lng,
      p.accuracy_m,
      p.device_time,
      p.created_at,
      p.status,
      p.province,
      p.city_municipality,
      p.barangay,
      p.assigned_station_id,
      ps.station_name AS assigned_station_name,
      ps.station_code AS assigned_station_code,
      u.firstname,
      u.lastname,
      u.email
    FROM panic_requests p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN police_stations ps ON ps.id = p.assigned_station_id
    $where
    ORDER BY p.created_at DESC
    LIMIT $limit
  ");
  foreach ($params as $k => $v) {
    if ($k === ":station_id") {
      $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
    } else {
      $stmt->bindValue($k, $v);
    }
  }
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "status" => $status,
    "panic" => array_map(function($r) {
      return [
        "id" => (int)$r["id"],
        "level" => $r["level"],
        "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
        "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
        "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
        "device_time" => $r["device_time"],
        "created_at" => $r["created_at"],
        "status" => $r["status"],
        "province" => $r["province"] ?? null,
        "city_municipality" => $r["city_municipality"] ?? null,
        "barangay" => $r["barangay"] ?? null,
        "assigned_station_id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
        "assigned_station_name" => $r["assigned_station_name"] ?? null,
        "assigned_station_code" => $r["assigned_station_code"] ?? null,
        "user" => [
          "name" => trim(($r["firstname"] ?? "") . " " . ($r["lastname"] ?? "")),
          "email" => $r["email"]
        ]
      ];
    }, $rows)
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