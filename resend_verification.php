<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode([
    "ok" => false,
    "message" => "Method not allowed"
  ]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

// Accept either email or username
$emailOrUsername = trim((string)($data["email"] ?? $data["username"] ?? ""));
$platform = trim((string)($data["platform"] ?? "web"));

if ($emailOrUsername === "") {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "Email or username is required"
  ]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      id,
      firstname,
      lastname,
      email,
      username,
      role,
      is_email_verified
    FROM users
    WHERE email = ? OR username = ?
    LIMIT 1
  ");
  $stmt->execute([$emailOrUsername, $emailOrUsername]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  // Don't leak whether account exists
  if (!$user) {
    http_response_code(200);
    echo json_encode([
      "ok" => true,
      "message" => "If the account exists, a verification email has been sent."
    ]);
    exit;
  }

  if ((int)$user["is_email_verified"] === 1) {
    http_response_code(409);
    echo json_encode([
      "ok" => false,
      "message" => "Email is already verified. Please login."
    ]);
    exit;
  }

  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24);

  $upd = $pdo->prepare("
    UPDATE users
    SET email_verify_token = ?, email_verify_expires = ?
    WHERE id = ?
  ");
  $upd->execute([$token, $expires, $user["id"]]);

  $appUrl = getenv("APP_URL") ?: "https://ebantay.top.gen.in";

  // With current hosting, safest universal route is verify.php
  // If you later split mobile/web flows by platform, you can branch here.
  $verifyLink = rtrim($appUrl, "/") . "/verify.php?token=" . $token;

  $fullName = trim(($user["firstname"] ?? "") . " " . ($user["lastname"] ?? ""));
  $recipientName = $fullName !== "" ? $fullName : ($user["username"] ?? "User");

  $sent = false;
  try {
    $sent = sendVerificationEmail($user["email"], $recipientName, $verifyLink);
  } catch (Throwable $e) {
    error_log("RESEND_VERIFICATION MAIL ERROR: " . $e->getMessage());
    $sent = false;
  }

  if (!$sent) {
    http_response_code(500);
    echo json_encode([
      "ok" => false,
      "message" => "Failed to send verification email. Please try again later."
    ]);
    exit;
  }

  echo json_encode([
    "ok" => true,
    "message" => "Verification email sent. Please check your inbox.",
    "email" => $user["email"]
  ]);
} catch (Throwable $e) {
  error_log("RESEND_VERIFICATION ERROR: " . $e->getMessage());

  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}
?>