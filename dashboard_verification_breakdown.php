<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$scope = admin_scope_from_auth($pdo, $AUTH_USER);

$statuses = ["PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
$items = [];

$params = [];
$where = " WHERE verification_status = ? ";
$provinceFilter = scope_where_clause("province", $scope, $params, ":scope_province");

$stmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM incident_reports
  $where
  $provinceFilter
");

foreach ($statuses as $status) {
  $execParams = array_merge([$status], array_values($params));
  $stmt->execute($execParams);
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
  "scope" => $scope,
  "items" => $items
]);