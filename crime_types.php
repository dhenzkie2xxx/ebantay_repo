<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

try {
  $stmt = $pdo->query("
    SELECT
      id,
      crime_category,
      focus_crime_code,
      crime_name,
      ciras_offense_code
    FROM crime_types
    WHERE is_active = 1
    ORDER BY crime_category ASC, crime_name ASC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $crimeTypes = array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "crime_category" => $r["crime_category"],
      "focus_crime_code" => $r["focus_crime_code"],
      "crime_name" => $r["crime_name"],
      "ciras_offense_code" => $r["ciras_offense_code"],
    ];
  }, $rows);

  out(200, [
    "ok" => true,
    "crime_types" => $crimeTypes
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}