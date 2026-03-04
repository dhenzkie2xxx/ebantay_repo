<?php
// cors.php — include this at the TOP of every API endpoint (or via require_admin.php)

$allowed = [
  "http://localhost:5173",
  "https://ebantay.top.gen.in"
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
}

// IMPORTANT: allow Authorization header for Bearer tokens
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 86400"); // cache preflight 24h

// Respond OK to preflight *before* any auth checks
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}