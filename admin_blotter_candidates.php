<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$q = trim($_GET["q"] ?? "");
$mode = strtoupper(trim($_GET["mode"] ?? "ALL")); // ALL | NEW | BLOTTERED

$where = " WHERE 1=1 ";
$params = [];

if ($mode === "NEW") {
  $where .= " AND verification_status = 'VERIFIED'
              AND (blotter_entry_number IS NULL OR blotter_entry_number = '') ";
} elseif ($mode === "BLOTTERED") {
  $where .= " AND incident_phase = 'BLOTTERED' ";
} else {
  $where .= " AND (
                (verification_status = 'VERIFIED' AND (blotter_entry_number IS NULL OR blotter_entry_number = ''))
                OR incident_phase = 'BLOTTERED'
              ) ";
}

if ($q !== "") {
  $where .= "
    AND (
      incident_code LIKE ?
      OR title LIKE ?
      OR incident_type LIKE ?
      OR barangay LIKE ?
      OR city_municipality LIKE ?
      OR blotter_entry_number LIKE ?
      OR irf_entry_number LIKE ?
    )
  ";
  $like = "%{$q}%";
  $params = [$like, $like, $like, $like, $like, $like, $like];
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
    blotter_entry_number,
    irf_entry_number,
    date_reported,
    created_at
  FROM incident_reports
  $where
  ORDER BY
    CASE WHEN incident_phase = 'BLOTTERED' THEN 0 ELSE 1 END,
    created_at DESC
  LIMIT 200
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
      "blotter_entry_number" => $r["blotter_entry_number"],
      "irf_entry_number" => $r["irf_entry_number"],
      "date_reported" => $r["date_reported"],
      "created_at" => $r["created_at"]
    ];
  }, $rows)
]);