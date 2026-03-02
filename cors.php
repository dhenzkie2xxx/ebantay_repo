<?php
$allowed = [
  "http://localhost:5173",
  "https://ebantay.top.gen.in"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}

// If you ever use cookies, keep this; otherwise it's okay to leave it.
header("Access-Control-Allow-Credentials: true");

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

// IMPORTANT: let preflight pass
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}