<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

// --- inputs ---
$status = strtoupper(trim($_GET["status"] ?? "PENDING"));
$allowed = ["PENDING","REVIEWED","RESOLVED","REJECTED"];
if (!in_array($status, $allowed, true)) $status = "PENDING";

$limit = (int)($_GET["limit"] ?? 50);
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");

// paging (optional; you were calling ?page=1)
$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$params = [":status" => $status];

$sql = "
  SELECT
    r.id, r.title, r.category, r.risk_status, r.risk_distance_m, r.risk_radius_m,
    r.lat, r.lng, r.created_at, r.status, r.admin_notes, r.reviewed_at, r.resolved_at,
    u.id AS user_id, u.firstname, u.lastname, u.email
  FROM incident_reports r
  JOIN users u ON u.id = r.user_id
  WHERE r.status = :status
";

if ($category !== "") {
  $sql .= " AND r.category = :category ";
  $params[":category"] = $category;
}

if ($q !== "") {
  $sql .= " AND (r.title LIKE :q OR r.description LIKE :q OR u.firstname LIKE :q OR u.lastname LIKE :q OR u.email LIKE :q) ";
  $params[":q"] = "%$q%";
}

$sql .= " ORDER BY r.created_at DESC LIMIT :limit OFFSET :offset ";

$stmt = $pdo->prepare($sql);

// bindValue required for LIMIT/OFFSET in PDO
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
$stmt->bindValue(":offset", $offset, PDO::PARAM_INT);

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "status" => $status,
  "page" => $page,
  "limit" => $limit,
  "reports" => array_map(function($r){
    return [
      "id" => (int)$r["id"],
      "title" => $r["title"],
      "category" => $r["category"],
      "risk_status" => $r["risk_status"],
      "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
      "risk_radius_m" => (int)$r["risk_radius_m"],
      "lat" => (float)$r["lat"],
      "lng" => (float)$r["lng"],
      "created_at" => $r["created_at"],
      "status" => $r["status"],
      "admin_notes" => $r["admin_notes"],
      "reviewed_at" => $r["reviewed_at"],
      "resolved_at" => $r["resolved_at"],
      "reporter" => [
        "id" => (int)$r["user_id"],
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"],
      ]
    ];
  }, $rows)
]);