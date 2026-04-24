<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->query("
    SELECT
      id,
      crime_name,
      crime_category,
      focus_crime_code,
      ciras_offense_code,
      severity_weight,
      is_active
    FROM crime_types
    WHERE is_active = 1
    ORDER BY crime_category ASC, crime_name ASC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    "ok" => true,
    "items" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "crime_name" => $r["crime_name"],
        "crime_category" => $r["crime_category"],
        "focus_crime_code" => $r["focus_crime_code"],
        "ciras_offense_code" => $r["ciras_offense_code"],
        "severity_weight" => isset($r["severity_weight"]) ? (float)$r["severity_weight"] : 2.0,
        "is_active" => (int)$r["is_active"]
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