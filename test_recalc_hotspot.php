<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

$id = (int)($_GET["id"] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing id"]);
  exit;
}

try {
  $res = recalc_hotspots_after_incident_save($pdo, $id);
  echo json_encode(["ok" => true, "result" => $res]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage(),
    "line" => $e->getLine()
  ]);
}