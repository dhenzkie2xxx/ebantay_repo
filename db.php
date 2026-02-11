<?php
header('Content-Type: application/json');

$host = "localhost";
$port = 3306;
$db   = "ebantay_db";
$user = "root";
$pass = "dhenzkie2000";
$charset = "utf8mb4";

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Database connection failed"]);
  exit;
}
?>
