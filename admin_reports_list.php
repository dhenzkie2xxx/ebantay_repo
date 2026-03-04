<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

// --- inputs ---
$status = strtoupper(trim($_GET["status"] ?? "ALL"));
$allowed = ["ALL","PENDING","REVIEWED","RESOLVED","REJECTED"];
if (!in_array($status, $allowed, true)) $status = "ALL";

$limit = (int)($_GET["limit"] ?? 50);
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");

// shared WHERE
$where = " WHERE 1=1 ";
$params = [];

if ($status !== "ALL") {
  $where .= " AND r.status = :status ";
  $params[":status"] = $status;
}

if ($category !== "") {
  $where .= " AND r.category = :category ";
  $params[":category"] = $category;
}

if ($q !== "") {
  $where .= " AND (
    r.title LIKE :q
    OR r.description LIKE :q
    OR r.category LIKE :q
    OR u.firstname LIKE :q
    OR u.lastname LIKE :q
    OR u.email LIKE :q
  ) ";
  $params[":q"] = "%{$q}%";
}

// total count (for pagination)
$countSql = "
  SELECT COUNT(*) AS total
  FROM incident_reports r
  JOIN users u ON u.id = r.user_id
  $where
";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

// list
$listSql = "
  SELECT
    r.id, r.title, r.category, r.risk_status, r.risk_distance_m, r.risk_radius_m,
    r.lat, r.lng, r.created_at, r.status, r.admin_notes, r.reviewed_at, r.resolved_at,
    u.id AS user_id, u.firstname, u.lastname, u.email,
    (
      SELECT COUNT(*)
      FROM incident_report_photos p
      WHERE p.report_id = r.id
    ) AS photo_count
  FROM incident_reports r
  JOIN users u ON u.id = r.user_id
  $where
  ORDER BY r.created_at DESC
  LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($listSql);
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
  "total" => $total,
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
      "photo_count" => (int)($r["photo_count"] ?? 0),
      "reporter" => [
        "id" => (int)$r["user_id"],
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"],
      ]
    ];
  }, $rows)
]);