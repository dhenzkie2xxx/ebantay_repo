<?php
header("Content-Type: application/json");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";
require __DIR__ . "/vendor/autoload.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid JSON body"]);
  exit;
}

$firstname = trim($data["firstname"] ?? "");
$lastname  = trim($data["lastname"] ?? "");
$email     = trim($data["email"] ?? "");
$username  = trim($data["username"] ?? "");
$password  = (string)($data["password"] ?? "");

if ($firstname === "" || $lastname === "" || $email === "" || $username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "All fields are required"]);
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid email format"]);
  exit;
}

if (strlen($username) < 3 || preg_match('/\s/', $username)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Username must be at least 3 characters and contain no spaces"]);
  exit;
}

if (strlen($password) < 6) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Password must be at least 6 characters"]);
  exit;
}

$check = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
$check->execute([$email, $username]);

if ($check->fetch()) {
  http_response_code(409);
  echo json_encode(["ok" => false, "message" => "Email or Username already exists"]);
  exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32));
$expires = date("Y-m-d H:i:s", time() + 86400);

$baseUrl = "https://ebantay.top.gen.in";
$verifyLink = $baseUrl . "/verify.php?token=" . $token;

$stmt = $pdo->prepare(
  "INSERT INTO users (
      lastname,
      firstname,
      email,
      username,
      password_hash,
      role,
      valid,
      is_email_verified,
      email_verify_token,
      email_verify_expires,
      account_status
   ) VALUES (?, ?, ?, ?, ?, 'admin', 'unvalid', 0, ?, ?, 'pending')"
);

$stmt->execute([
  $lastname,
  $firstname,
  $email,
  $username,
  $passwordHash,
  $token,
  $expires
]);

$fullName = $firstname . " " . $lastname;
$sent = false;
$mailError = null;

try {
  $sent = sendVerificationEmail($email, $fullName, $verifyLink);
} catch (Throwable $e) {
  $mailError = $e->getMessage();
}

echo json_encode([
  "ok" => true,
  "message" => $sent
    ? "Admin registration successful. Please verify your email."
    : "Admin registration successful, but failed to send verification email.",
  "needs_verification" => true,
  "mail_sent" => $sent,
  "mail_error" => $mailError
]);
exit;