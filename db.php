<?php
header('Content-Type: application/json');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

if (!$host || !$db || !$user) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Missing DB environment variables (DB_HOST/DB_NAME/DB_USER)"
  ]);
  exit;
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
];

// ✅ Aiven requires SSL. Use CA cert if present.
$caPath = __DIR__ . "/ca.pem";
if (file_exists($caPath)) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
}

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "DB connection failed",
    // TEMP while debugging; remove later
    "error" => $e->getMessage()
  ]);
  exit;
}
