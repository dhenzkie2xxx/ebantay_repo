<?php
require_once __DIR__ . "/cors.php";
header("Content-Type: application/json; charset=UTF-8");

echo json_encode([
  "ok" => true,
  "message" => "API is up (no DB check)",
  "time" => date("c"),
  "has_ca" => file_exists(__DIR__ . "/ca.pem"),
]);