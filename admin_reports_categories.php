<?php
require_once __DIR__ . "/require_admin.php";
header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->query("
    SELECT incident_type AS category, COUNT(*) AS total
    FROM incident_reports
    WHERE incident_type IS NOT NULL AND incident_type <> ''
    GROUP BY incident_type
    ORDER BY total DESC, incident_type ASC
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "categories" => array_map(function($r){
      return [
        "name" => $r["category"],
        "total" => (int)$r["total"]
      ];
    }, $rows)
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}