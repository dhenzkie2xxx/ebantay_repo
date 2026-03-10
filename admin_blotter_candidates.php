<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$q = trim($_GET["q"] ?? "");

$where = "
  WHERE verification_status = 'VERIFIED'
    AND (blotter_entry_number IS NULL OR blotter_entry_number = '')
";

$params = [];

if ($q !== "") {
  $where .= "
    AND (
      incident_code LIKE ?
      OR title LIKE ?
      OR incident_type LIKE ?
      OR barangay LIKE ?
      OR city_municipality LIKE ?
    )
  ";
  $like = "%{$q}%";
  $params = [$like, $like, $like, $like, $like];
}

$sql = "
  SELECT
    id,
    incident_code,
    title,
    incident_type,
    barangay,
    city_municipality,
    verification_status,
    incident_phase,
    date_reported,
    created_at
  FROM incident_reports
  $where
  ORDER BY created_at DESC
  LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "items" => array_map(function($r) {
    return [
      "id" => (int)$r["id"],
      "incident_code" => $r["incident_code"],
      "title" => $r["title"],
      "incident_type" => $r["incident_type"],
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "verification_status" => $r["verification_status"],
      "incident_phase" => $r["incident_phase"],
      "date_reported" => $r["date_reported"],
      "created_at" => $r["created_at"]
    ];
  }, $rows)
]);