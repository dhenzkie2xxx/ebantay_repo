<?php
header('Content-Type: application/json');

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/mailer.php';

$ok = sendVerificationEmail("dhenmarcginos@gmail.com", "Test User", "https://example.com");

echo json_encode([
  "ok" => $ok,
  "message" => $ok ? "Email sent" : "Email failed (check Render logs)"
]);