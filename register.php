<?php
require_once __DIR__ . "/db.php";
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . "/mailer.php";

// Only allow POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

// Read JSON
$data = json_decode(file_get_contents("php://input"), true);

// Use consistent keys (support both cases to avoid RN mismatch)
$firstname = trim($data["firstname"] ?? $data["Firstname"] ?? "");
$lastname  = trim($data["lastname"]  ?? $data["Lastname"]  ?? "");
$email     = trim($data["email"]     ?? $data["Email"]     ?? "");
$username  = trim($data["username"]  ?? "");
$password  = $data["password"] ?? "";

// Validate required fields
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

if (strlen($password) < 6) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Password must be at least 6 characters"]);
  exit;
}

// Check duplicates
$check = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
$check->execute([$email, $username]);

if ($check->fetch()) {
  http_response_code(409);
  echo json_encode(["ok" => false, "message" => "Email or Username already exists"]);
  exit;
}

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Create verification token + expiry (24 hours)
$token = bin2hex(random_bytes(32)); // 64 chars
$expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24);

$baseUrl = "http://192.168.140.38/ebantay/api";

$verifyLink = $baseUrl . "/verify.php?token=" . $token;

$stmt = $pdo->prepare(
  "INSERT INTO users (lastname, firstname, email, username, password_hash, role, valid, is_email_verified, email_verify_token, email_verify_expires)
   VALUES (?, ?, ?, ?, ?, 'citizen', 'unvalid', 0, ?, ?)"
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

// Send email (PHPMailer)
require __DIR__ . '/vendor/autoload.php';
$fullName = $firstname . " " . $lastname;

$sent = sendVerificationEmail($email, $fullName, $verifyLink);

echo json_encode([
  "ok" => true,
  "message" => $sent
    ? "Registration successful. Please verify your email."
    : "Registration successful, but failed to send verification email.",
  "needs_verification" => true
]);
