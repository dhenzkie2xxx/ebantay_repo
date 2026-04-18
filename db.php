<?php
// db.php (DO NOT echo/print anything here; do not set global headers here)

// ✅ Set PHP timezone to Philippines
date_default_timezone_set('Asia/Manila');

$host = getenv('DB_HOST');
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

if (!$host || !$db || !$user) {
  http_response_code(500);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode([
    "ok" => false,
    "message" => "Missing DB environment variables (DB_HOST/DB_NAME/DB_USER)"
  ]);
  exit;
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

  // ✅ fail fast instead of hanging
  PDO::ATTR_TIMEOUT => 5,
];

// ✅ Aiven SSL (recommended). If you have ca.pem, use it.
$caPath = __DIR__ . "/ca.pem";
if (file_exists($caPath)) {
  $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
  // Optional:
  // $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
  $pdo = new PDO($dsn, $user, $pass, $options);

  // ✅ FIX: Use Philippine timezone instead of UTC
  $pdo->exec("SET time_zone = '+08:00'");

} catch (PDOException $e) {
  http_response_code(500);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode([
    "ok" => false,
    "message" => "DB connection failed",
    "error" => $e->getMessage() // TEMP; remove later in production
  ]);
  exit;
}