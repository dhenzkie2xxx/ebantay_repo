<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->query("
    SELECT id
    FROM incident_reports
    WHERE incident_code LIKE 'SEED-%'
      AND verification_status = 'VERIFIED'
      AND incident_phase IN ('RESOLVED','UNDER_INVESTIGATION','BLOTTERED','FILED_IN_COURT')
      AND lat IS NOT NULL
      AND lng IS NOT NULL
    ORDER BY id ASC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $results = [];

  foreach ($rows as $r) {
    $results[] = [
      "incident_id" => (int)$r["id"],
      "result" => hotspot_auto_create_from_incident($pdo, (int)$r["id"], 3650, 500, 2)
    ];
  }

  echo json_encode([
    "ok" => true,
    "processed" => count($rows),
    "results" => $results
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage(),
    "line" => $e->getLine()
  ]);
}