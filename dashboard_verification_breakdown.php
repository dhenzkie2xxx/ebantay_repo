<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
header("Content-Type: application/json; charset=UTF-8");

$statuses = ["PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
$items = [];

$stmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM incident_reports
  WHERE verification_status = ?
");

foreach ($statuses as $status) {
  $stmt->execute([$status]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  $label = $status;
  if ($status === "FALSE_REPORT") $label = "False Report";
  if ($status === "DUPLICATE") $label = "Duplicate";

  $items[] = [
    "status" => $status,
    "label" => ucwords(strtolower(str_replace("_", " ", $label))),
    "value" => (int)($row["c"] ?? 0)
  ];
}

echo json_encode([
  "ok" => true,
  "items" => $items
]);