<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $status = strtolower(trim($_GET["status"] ?? "new"));
  $allowed = ["all","new","ack","resolved"];
  if (!in_array($status, $allowed, true)) $status = "new";

  $limit = (int)($_GET["limit"] ?? 80);
  if ($limit < 1) $limit = 80;
  if ($limit > 200) $limit = 200;

  $where = " WHERE 1=1 ";
  $params = [];

  $where .= scope_where_clause("p.province", $scope, $params, ":scope_province");

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
      u.firstname,
      u.lastname,
      u.email
    FROM panic_requests p
    JOIN users u ON u.id = p.user_id
    $where
    ORDER BY p.created_at DESC
    LIMIT $limit
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "status" => $status,
    "panic" => array_map(function($r){
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