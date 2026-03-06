<?php
require_once __DIR__ . "/require_admin.php";

// DO NOT redeclare bearer_token() here.
// require_admin.php already handles CORS + auth.

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$status = strtoupper(trim($_GET["status"] ?? "ALL"));
$allowed = ["ALL", "PENDING", "REVIEWED", "RESOLVED", "REJECTED"];
if (!in_array($status, $allowed, true)) $status = "ALL";

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");
$idsRaw = trim($_GET["ids"] ?? ""); // comma-separated optional

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

$idList = [];
if ($idsRaw !== "") {
  $idList = array_values(array_unique(array_filter(array_map("intval", explode(",", $idsRaw)), fn($v) => $v > 0)));
  if (count($idList) > 0) {
    $ph = [];
    foreach ($idList as $i => $id) {
      $key = ":id{$i}";
      $ph[] = $key;
      $params[$key] = $id;
    }
    $where .= " AND r.id IN (" . implode(",", $ph) . ") ";
  }
}

$sql = "
  SELECT
    r.id,
    r.title,
    r.category,
    r.description,
    r.status,
    r.risk_status,
    r.risk_distance_m,
    r.risk_radius_m,
    r.lat,
    r.lng,
    r.accuracy_m,
    r.device_time,
    r.created_at,
    r.admin_notes,
    r.reviewed_at,
    r.resolved_at,
    u.firstname,
    u.lastname,
    u.email
  FROM incident_reports r
  JOIN users u ON u.id = r.user_id
  $where
  ORDER BY r.created_at DESC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "incident_reports_" . gmdate("Ymd_His") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$out = fopen("php://output", "w");

// UTF-8 BOM for Excel
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, [
  "ID",
  "Title",
  "Category",
  "Description",
  "Status",
  "Risk Status",
  "Risk Distance (m)",
  "Risk Radius (m)",
  "Latitude",
  "Longitude",
  "Accuracy (m)",
  "Device Time",
  "Created At",
  "Admin Notes",
  "Reviewed At",
  "Resolved At",
  "Reporter Firstname",
  "Reporter Lastname",
  "Reporter Email"
]);

foreach ($rows as $r) {
  fputcsv($out, [
    $r["id"],
    $r["title"],
    $r["category"],
    $r["description"],
    $r["status"],
    $r["risk_status"],
    $r["risk_distance_m"],
    $r["risk_radius_m"],
    $r["lat"],
    $r["lng"],
    $r["accuracy_m"],
    $r["device_time"],
    $r["created_at"],
    $r["admin_notes"],
    $r["reviewed_at"],
    $r["resolved_at"],
    $r["firstname"],
    $r["lastname"],
    $r["email"]
  ]);
}

fclose($out);
exit;