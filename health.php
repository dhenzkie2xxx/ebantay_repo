<?php
require_once __DIR__ . "/db.php";
echo json_encode([
  "ok" => true,
  "message" => "API is up",
  "time" => date("c")
]);
