<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$limit = (int)($_GET["limit"] ?? 7);
if ($limit < 1) $limit = 7;
if ($limit > 20) $limit = 20;

$scope = admin_scope_from_auth($pdo, $AUTH_USER);

$params = [];
$where = " WHERE verification_status = 'VERIFIED' ";
$where .= scope_where_clause("province", $scope, $params, ":scope_province");

$sql = "
  SELECT barangay, COUNT(*) AS count
  FROM incident_reports
  $where
  GROUP BY barangay
  ORDER BY count DESC, barangay ASC
  LIMIT $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$items = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $items[] = [
    "barangay" => $row["barangay"] ?: "Unknown",
    "count" => (int)$row["count"]
  ];
}

echo json_encode([
  "ok" => true,
  "scope" => $scope,
  "items" => $items
]);