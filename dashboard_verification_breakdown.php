<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$scope = admin_scope_from_auth($pdo, $AUTH_USER);

$statuses = ["PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
$items = [];

$role = strtolower(trim((string)($AUTH_USER["role"] ?? "")));
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;

$where = "";
$params = [];

if ($role === "admin") {
  if ($stationId <= 0) {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "message" => "Admin station is not configured."
    ]);
    exit;
  }

  $where = " AND assigned_station_id = :station_id ";
  $params[":station_id"] = $stationId;
}

$stmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM incident_reports
  WHERE verification_status = :verification_status
  $where
");

foreach ($statuses as $status) {
  $execParams = array_merge(
    [":verification_status" => $status],
    $params
  );

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