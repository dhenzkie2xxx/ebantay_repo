<?php
require_once __DIR__ . "/require_admin.php";

$status = strtolower(trim($_GET["status"] ?? "new"));
$allowed = ["new","ack","resolved"];
if (!in_array($status, $allowed, true)) $status = "new";

$limit = (int)($_GET["limit"] ?? 80);
if ($limit < 1) $limit = 80;
if ($limit > 200) $limit = 200;

$stmt = $pdo->prepare("
  SELECT
    p.id, p.level, p.lat, p.lng, p.accuracy_m, p.device_time, p.created_at, p.status,
    u.firstname, u.lastname, u.email
  FROM panic_requests p
  JOIN users u ON u.id = p.user_id
  WHERE p.status = ?
  ORDER BY p.created_at DESC
  LIMIT $limit
");
$stmt->execute([$status]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "status" => $status,
  "panic" => array_map(function($r){
    return [
      "id" => (int)$r["id"],
      "level" => $r["level"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
      "device_time" => $r["device_time"],
      "created_at" => $r["created_at"],
      "status" => $r["status"],
      "user" => [
        "name" => $r["firstname"]." ".$r["lastname"],
        "email" => $r["email"]
      ]
    ];
  }, $rows)
]);