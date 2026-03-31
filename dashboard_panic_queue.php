<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $limit = (int)($_GET["limit"] ?? 6);
  if ($limit < 1) $limit = 6;
  if ($limit > 20) $limit = 20;

  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $params = [];
  $where = " WHERE p.status IN ('new', 'ack') ";
  $where .= scope_where_clause("p.province", $scope, $params, ":scope_province");

  $sql = "
    SELECT
      p.id,
      p.level,
      p.status,
      p.created_at,
      p.province,
      p.city_municipality,
      p.barangay,
      u.firstname,
      u.lastname,
      u.email
    FROM panic_requests p
    JOIN users u ON u.id = p.user_id
    $where
    ORDER BY
      CASE p.level
        WHEN 'urgent' THEN 1
        WHEN 'alert' THEN 2
        ELSE 3
      END,
      p.created_at DESC
    LIMIT $limit
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$row["id"],
      "level" => $row["level"],
      "status" => $row["status"],
      "created_at" => gmdate("Y-m-d H:i", strtotime($row["created_at"])),
      "user_name" => trim(($row["firstname"] ?? "") . " " . ($row["lastname"] ?? "")),
      "email" => $row["email"],
      "province" => $row["province"] ?? null,
      "city_municipality" => $row["city_municipality"] ?? null,
      "barangay" => $row["barangay"] ?? null
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