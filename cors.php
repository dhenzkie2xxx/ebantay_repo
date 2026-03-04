<?php
// cors.php
$allowed = [
  "http://localhost:5173",
  "http://127.0.0.1:5173",
  "http://localhost:3000",
  "http://127.0.0.1:3000",
  "https://ebantay.top.gen.in",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

// Always vary by Origin for caching proxies
header("Vary: Origin");

// Reflect allowed origins only
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
} else {
  // IMPORTANT: Do NOT set Allow-Origin if origin isn't allowed
  // (Browser will block; that's correct)
}

// Allow Bearer token header
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 86400");

// Debug header (optional, you can remove later)
header("X-CORS-RAN: 1");

// Preflight: return 200 immediately
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}