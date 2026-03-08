<?php
require_once __DIR__ . "/require_admin.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$verification_status = strtoupper(trim($_GET["status"] ?? "ALL"));
$allowed = ["ALL","PENDING","VERIFIED","FALSE_REPORT","DUPLICATE"];
if (!in_array($verification_status, $allowed, true)) $verification_status = "ALL";

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");
$idsRaw = trim($_GET["ids"] ?? "");

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

if ($idsRaw !== "") {
  $idList = array_values(array_unique(array_filter(array_map("intval", explode(",", $idsRaw)), fn($v) => $v > 0)));
  if ($idList) {
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
    r.incident_code,
    r.title,
    r.incident_type,
    r.crime_category,
    r.narrative,
    r.verification_status,
    r.incident_phase,
    r.case_status,
    r.risk_status,
    r.risk_distance_m,
    r.risk_radius_m,
    r.lat,
    r.lng,
    r.accuracy_m,
    r.device_time,
    r.created_at,
    r.date_reported,
    r.date_incident_from,
    r.barangay,
    r.city_municipality,
    r.province,
    r.admin_notes,
    r.reviewed_at,
    r.resolved_at,
    u.firstname,
    u.lastname,
    u.email
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  $where
  ORDER BY r.created_at DESC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "incident_reports_" . gmdate("Ymd_His") . ".csv";

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

$out = fopen("php://output", "w");
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, [
  "ID",
  "Incident Code",
  "Title",
  "Incident Type",
  "Crime Category",
  "Narrative",
  "Verification Status",
  "Incident Phase",
  "Case Status",
  "Risk Status",
  "Risk Distance (m)",
  "Risk Radius (m)",
  "Latitude",
  "Longitude",
  "Accuracy (m)",
  "Device Time",
  "Created At",
  "Date Reported",
  "Date Incident From",
  "Barangay",
  "City/Municipality",
  "Province",
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
    $r["incident_code"],
    $r["title"],
    $r["incident_type"],
    $r["crime_category"],
    $r["narrative"],
    $r["verification_status"],
    $r["incident_phase"],
    $r["case_status"],
    $r["risk_status"],
    $r["risk_distance_m"],
    $r["risk_radius_m"],
    $r["lat"],
    $r["lng"],
    $r["accuracy_m"],
    $r["device_time"],
    $r["created_at"],
    $r["date_reported"],
    $r["date_incident_from"],
    $r["barangay"],
    $r["city_municipality"],
    $r["province"],
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