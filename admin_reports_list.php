<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") out(405, ["ok"=>false, "message"=>"Method not allowed"]);

$status = strtoupper(trim($_GET["status"] ?? "")); // "" = ALL
$allowed = ["PENDING","REVIEWED","RESOLVED","REJECTED"];
if ($status !== "" && !in_array($status, $allowed, true)) $status = "";

$page = max(1, (int)($_GET["page"] ?? 1));
$limit = (int)($_GET["limit"] ?? 10);
if ($limit < 5) $limit = 5;
if ($limit > 50) $limit = 50;
$offset = ($page - 1) * $limit;

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");
$dateFrom = trim($_GET["dateFrom"] ?? ""); // optional YYYY-MM-DD
$dateTo = trim($_GET["dateTo"] ?? "");     // optional YYYY-MM-DD

$where = [];
$params = [];

if ($status !== "") {
  $where[] = "r.status = :status";
  $params[":status"] = $status;
}

if ($category !== "") {
  $where[] = "r.category = :category";
  $params[":category"] = $category;
}

if ($q !== "") {
  $where[] = "(r.title LIKE :q OR r.description LIKE :q OR u.firstname LIKE :q OR u.lastname LIKE :q OR u.email LIKE :q OR u.username LIKE :q)";
  $params[":q"] = "%$q%";
}

if ($dateFrom !== "") {
  $where[] = "DATE(r.created_at) >= :dateFrom";
  $params[":dateFrom"] = $dateFrom;
}

if ($dateTo !== "") {
  $where[] = "DATE(r.created_at) <= :dateTo";
  $params[":dateTo"] = $dateTo;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

try {
  // total count
  $stmtTotal = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM incident_reports r
    JOIN users u ON u.id = r.user_id
    $whereSql
  ");
  $stmtTotal->execute($params);
  $total = (int)($stmtTotal->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

  // list
  $stmt = $pdo->prepare("
    SELECT
      r.id, r.title, r.category, r.risk_status, r.risk_distance_m, r.risk_radius_m,
      r.lat, r.lng, r.created_at, r.status,
      u.id AS user_id, u.firstname, u.lastname, u.email, u.username,
      (SELECT COUNT(*) FROM incident_report_photos p WHERE p.report_id = r.id) AS photo_count
    FROM incident_reports r
    JOIN users u ON u.id = r.user_id
    $whereSql
    ORDER BY r.created_at DESC
    LIMIT $limit OFFSET $offset
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  out(200, [
    "ok" => true,
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
        "photo_count" => (int)$r["photo_count"],
        "reporter" => [
          "id" => (int)$r["user_id"],
          "firstname" => $r["firstname"],
          "lastname" => $r["lastname"],
          "email" => $r["email"],
          "username" => $r["username"],
        ]
      ];
    }, $rows)
  ]);
} catch (Throwable $e) {
  out(500, ["ok"=>false, "message"=>"Server error"]);
}