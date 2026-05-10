<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$token = trim((string)($data["token"] ?? ""));
$password = (string)($data["password"] ?? "");

if ($token === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Token and password are required"]);
  exit;
}

if (strlen($password) < 6) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Password must be at least 6 characters"]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id, password_reset_expires
    FROM users
    WHERE password_reset_token = ?
    LIMIT 1
  ");
  $stmt->execute([$token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid or already used reset link"]);
    exit;
  }

  $expires = !empty($user["password_reset_expires"])
    ? strtotime($user["password_reset_expires"])
    : 0;

  if ($expires <= 0 || time() > $expires) {
    $clear = $pdo->prepare("
      UPDATE users
      SET password_reset_token = NULL,
          password_reset_expires = NULL
      WHERE id = ?
    ");
    $clear->execute([$user["id"]]);

    http_response_code(410);
    echo json_encode(["ok" => false, "message" => "Reset link has expired"]);
    exit;
  }

  $passwordHash = password_hash($password, PASSWORD_DEFAULT);

  $upd = $pdo->prepare("
    UPDATE users
    SET password_hash = ?,
        password_reset_token = NULL,
        password_reset_expires = NULL,
        api_token = NULL,
        api_token_expires = NULL
    WHERE id = ?
  ");
  $upd->execute([$passwordHash, $user["id"]]);

  echo json_encode([
    "ok" => true,
    "message" => "Password reset successful. Please login with your new password."
  ]);
} catch (Throwable $e) {
  error_log("RESET_PASSWORD ERROR: " . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}
?>