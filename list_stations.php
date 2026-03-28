<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$status = trim((string)($_GET["status"] ?? ""));
$keyword = trim((string)($_GET["keyword"] ?? ""));
$limit = (int)($_GET["limit"] ?? 20);

if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;

$allowedStatuses = [
  "draft",
  "pending",
  "under_review",
  "approved",
  "rejected",
  "resubmission_required"
];

$where = [];
$params = [];

if ($status !== "") {
  if (!in_array($status, $allowedStatuses, true)) {
    auth_out(400, ["ok" => false, "message" => "Invalid status filter."]);
  }
  $where[] = "ps.verification_status = ?";
  $params[] = $status;
}

if ($keyword !== "") {
  $where[] = "(
    ps.station_name LIKE ?
    OR ps.station_code LIKE ?
    OR ps.city_municipality LIKE ?
    OR ps.province LIKE ?
    OR ps.region LIKE ?
    OR u.firstname LIKE ?
    OR u.lastname LIKE ?
    OR u.username LIKE ?
    OR u.email LIKE ?
  )";
  $kw = "%" . $keyword . "%";
  for ($i = 0; $i < 9; $i++) {
    $params[] = $kw;
  }
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$sql = "
  SELECT
    ps.id,
    ps.station_code,
    ps.station_name,
    ps.station_type,
    ps.region,
    ps.province,
    ps.city_municipality,
    ps.barangay,
    ps.operating_hours,
    ps.verification_status,
    ps.is_active,
    ps.submitted_at,
    ps.reviewed_at,
    ps.approved_at,
    ps.rejection_reason,
    ps.created_at,
    u.id AS admin_user_id,
    u.firstname,
    u.lastname,
    u.username,
    u.email,
    u.account_status,
    (
      SELECT COUNT(*)
      FROM police_station_documents d
      WHERE d.station_id = ps.id
    ) AS document_count
  FROM police_stations ps
  JOIN users u ON u.id = ps.created_by
  $whereSql
  ORDER BY
    CASE ps.verification_status
      WHEN 'pending' THEN 1
      WHEN 'under_review' THEN 2
      WHEN 'resubmission_required' THEN 3
      WHEN 'rejected' THEN 4
      WHEN 'approved' THEN 5
      WHEN 'draft' THEN 6
      ELSE 7
    END,
    ps.submitted_at DESC,
    ps.created_at DESC
  LIMIT $limit
";

try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$row["id"],
      "station_code" => $row["station_code"],
      "station_name" => $row["station_name"],
      "station_type" => $row["station_type"],
      "region" => $row["region"],
      "province" => $row["province"],
      "city_municipality" => $row["city_municipality"],
      "barangay" => $row["barangay"],
      "operating_hours" => $row["operating_hours"],
      "verification_status" => $row["verification_status"],
      "is_active" => (int)$row["is_active"],
      "submitted_at" => $row["submitted_at"],
      "reviewed_at" => $row["reviewed_at"],
      "approved_at" => $row["approved_at"],
      "rejection_reason" => $row["rejection_reason"],
      "created_at" => $row["created_at"],
      "admin" => [
        "id" => (int)$row["admin_user_id"],
        "firstname" => $row["firstname"],
        "lastname" => $row["lastname"],
        "username" => $row["username"],
        "email" => $row["email"],
        "account_status" => $row["account_status"]
      ],
      "document_count" => (int)$row["document_count"]
    ];
  }

  auth_out(200, [
    "ok" => true,
    "items" => $items
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error.",
    "error" => $e->getMessage()
  ]);
}