<?php
require_once __DIR__ . "/cors.php";
header("Content-Type: application/json; charset=UTF-8");

$start = microtime(true);

require_once __DIR__ . "/db.php";

try {
  $pdo->query("SELECT 1")->fetch();
  $ms = (int)((microtime(true) - $start) * 1000);

  echo json_encode([
    "ok" => true,
    "message" => "DB OK",
    "db_ms" => $ms,
    "time" => date("c"),
    "has_ca" => file_exists(__DIR__ . "/ca.pem"),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "DB FAILED",
    "error" => $e->getMessage(),
  ]);
}