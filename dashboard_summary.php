<?php
$allowed = [
  "http://localhost:5173",
  "https://ebantay.top.gen.in"
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? "";
if (in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function one($pdo, $sql) {
  $q = $pdo->query($sql);
  $row = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
  return (int)($row["c"] ?? 0);
}

echo json_encode([
  "ok" => true,
  "cards" => [
    "totalReports" => one($pdo, "SELECT COUNT(*) c FROM incident_reports"),
    "reportsThisWeek" => one($pdo, "SELECT COUNT(*) c FROM incident_reports WHERE YEARWEEK(created_at, 1)=YEARWEEK(UTC_DATE(), 1)"),
    "pendingReports" => one($pdo, "SELECT COUNT(*) c FROM incident_reports WHERE status='PENDING'"),
    "resolvedReports" => one($pdo, "SELECT COUNT(*) c FROM incident_reports WHERE status='RESOLVED'"),
    "panicNew" => one($pdo, "SELECT COUNT(*) c FROM panic_requests WHERE status='new'")
  ]
]);