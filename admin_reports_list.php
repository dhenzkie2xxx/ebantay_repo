<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$verification_status = strtoupper(trim($_GET["status"] ?? "ALL"));
$allowed = ["ALL","PENDING","VERIFIED","FALSE_REPORT","DUPLICATE"];
if (!in_array($verification_status, $allowed, true)) $verification_status = "ALL";

$limit = (int)($_GET["limit"] ?? 10);
if ($limit < 1) $limit = 10;
if ($limit > 200) $limit = 200;

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");

$where = " WHERE 1=1 ";
$params = [];

if ($verification_status !== "ALL") {
  $where .= " AND r.verification_status = :verification_status ";
  $params[":verification_status"] = $verification_status;
}

if ($category !== "") {
  $where .= " AND (r.incident_type = :category OR r.crime_category = :category) ";
  $params[":category"] = $category;
}

if ($q !== "") {
  $where .= " AND (
    r.incident_code LIKE :q
    OR r.title LIKE :q
    OR r.narrative LIKE :q
    OR r.incident_type LIKE :q
    OR r.crime_category LIKE :q
    OR u.firstname LIKE :q
    OR u.lastname LIKE :q
    OR u.email LIKE :q
    OR r.barangay LIKE :q
    OR r.city_municipality LIKE :q
  ) ";
  $params[":q"] = "%{$q}%";
}

$countSql = "
  SELECT COUNT(*) AS total
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  $where
";

$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

$listSql = "
  SELECT
    r.id,
    r.incident_code,
    r.title,
    r.incident_type,
    r.crime_category,
    r.narrative,
    r.risk_status,
    r.risk_distance_m,
    r.risk_radius_m,
    r.is_hotspot_related,
    r.hotspot_id,
    r.lat,
    r.lng,
    r.barangay,
    r.city_municipality,
    r.created_at,
    r.date_reported,
    r.date_incident_from,
    r.verification_status,
    r.incident_phase,
    r.case_status,
    r.admin_notes,
    r.reviewed_at,
    r.resolved_at,
    u.id AS user_id,
    u.firstname,
    u.lastname,
    u.email,
    (
      SELECT COUNT(*)
      FROM incident_report_photos p
      WHERE p.incident_id = r.id
    ) AS photo_count
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
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
  "status" => $verification_status,
  "page" => $page,
  "limit" => $limit,
  "total" => $total,
  "reports" => array_map(function($r){
    return [
      "id" => (int)$r["id"],
      "incident_code" => $r["incident_code"],
      "title" => $r["title"],
      "category" => $r["incident_type"],
      "crime_category" => $r["crime_category"],
      "description" => $r["narrative"],
      "verification_status" => $r["verification_status"],
      "incident_phase" => $r["incident_phase"],
      "case_status" => $r["case_status"],
      "risk_status" => $r["risk_status"],
      "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
      "risk_radius_m" => $r["risk_radius_m"] !== null ? (int)$r["risk_radius_m"] : null,
      "is_hotspot_related" => (int)$r["is_hotspot_related"],
      "hotspot_id" => $r["hotspot_id"] !== null ? (int)$r["hotspot_id"] : null,
      "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
      "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "created_at" => $r["created_at"],
      "date_reported" => $r["date_reported"],
      "date_incident_from" => $r["date_incident_from"],
      "admin_notes" => $r["admin_notes"],
      "reviewed_at" => $r["reviewed_at"],
      "resolved_at" => $r["resolved_at"],
      "photo_count" => (int)($r["photo_count"] ?? 0),
      "reporter" => [
        "id" => $r["user_id"] !== null ? (int)$r["user_id"] : null,
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"]
      ]
    ];
  }, $rows)
]);