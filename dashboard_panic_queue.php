<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$limit = (int)($_GET["limit"] ?? 6);
if ($limit < 1) $limit = 6;
if ($limit > 20) $limit = 20;

$sql = "
  SELECT
    p.id,
    p.level,
    p.status,
    p.created_at,
    u.firstname,
    u.lastname,
    u.email
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  WHERE p.status IN ('new', 'ack')
  ORDER BY
    CASE p.level
      WHEN 'urgent' THEN 1
      WHEN 'alert' THEN 2
      ELSE 3
    END,
    p.created_at DESC
  LIMIT $limit
";

$stmt = $pdo->query($sql);
$items = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $items[] = [
    "id" => (int)$row["id"],
    "level" => $row["level"],
    "status" => $row["status"],
    "created_at" => gmdate("Y-m-d H:i", strtotime($row["created_at"])),
    "user_name" => trim(($row["firstname"] ?? "") . " " . ($row["lastname"] ?? "")),
    "email" => $row["email"]
  ];
}

echo json_encode([
  "ok" => true,
  "items" => $items
]);