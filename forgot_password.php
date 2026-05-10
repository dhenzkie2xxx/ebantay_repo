<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$emailOrUsername = trim((string)($data["email"] ?? $data["username"] ?? ""));

if ($emailOrUsername === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Email or username is required"]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id, firstname, lastname, email, username
    FROM users
    WHERE email = ? OR username = ?
    LIMIT 1
  ");
  $stmt->execute([$emailOrUsername, $emailOrUsername]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  // Do not reveal if the account exists
  if (!$user) {
    echo json_encode([
      "ok" => true,
      "message" => "If the account exists, a password reset link has been sent."
    ]);
    exit;
  }

  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 60 * 60); // 1 hour

  $upd = $pdo->prepare("
    UPDATE users
    SET password_reset_token = ?, password_reset_expires = ?
    WHERE id = ?
  ");
  $upd->execute([$token, $expires, $user["id"]]);

  $appUrl = getenv("APP_URL") ?: "https://ebantay.top.gen.in";
  $resetLink = "ebantay://reset-password?token=" . urlencode($token);

  $fullName = trim(($user["firstname"] ?? "") . " " . ($user["lastname"] ?? ""));
  $recipientName = $fullName !== "" ? $fullName : ($user["username"] ?? "User");

  $sent = false;

  try {
    $sent = sendPasswordResetEmail($user["email"], $recipientName, $resetLink);
  } catch (Throwable $e) {
    error_log("FORGOT_PASSWORD MAIL ERROR: " . $e->getMessage());
    $sent = false;
  }

  if (!$sent) {
    http_response_code(500);
    echo json_encode([
      "ok" => false,
      "message" => "Failed to send password reset email. Please try again later."
    ]);
    exit;
  }

  echo json_encode([
    "ok" => true,
    "message" => "Password reset link sent. Please check your email."
  ]);
} catch (Throwable $e) {
  error_log("FORGOT_PASSWORD ERROR: " . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}
?>