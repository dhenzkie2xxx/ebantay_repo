<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid JSON body"]);
  exit;
}

/* ================= INPUT ================= */
$firstname = trim($data["firstname"] ?? "");
$lastname  = trim($data["lastname"] ?? "");
$email     = trim($data["email"] ?? "");
$username  = trim($data["username"] ?? "");
$password  = (string)($data["password"] ?? "");

/* ================= VALIDATION ================= */
if (!$firstname || !$lastname || !$email || !$username || !$password) {
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

try {
  /* ================= DUPLICATE CHECK ================= */
  $check = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
  $check->execute([$email, $username]);

  if ($check->fetch()) {
    http_response_code(409);
    echo json_encode(["ok" => false, "message" => "Email or username already exists"]);
    exit;
  }

  /* ================= CREATE USER ================= */
  $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 86400);

  $stmt = $pdo->prepare("
    INSERT INTO users (
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
      account_status,
      station_id
    ) VALUES (?, ?, ?, ?, ?, 'admin', 'unvalid', 0, ?, ?, 'pending', NULL)
  ");

  $stmt->execute([
    $lastname,
    $firstname,
    $email,
    $username,
    $passwordHash,
    $token,
    $expires
  ]);

  /* ================= EMAIL ================= */
  $baseUrl = "https://ebantay.top.gen.in";
  $verifyLink = $baseUrl . "/verify.php?token=" . $token;

  $fullName = $firstname . " " . $lastname;

  $sent = false;
  $mailError = null;

  try {
    $sent = sendVerificationEmail($email, $fullName, $verifyLink);
  } catch (Throwable $e) {
    $mailError = $e->getMessage();
    error_log("MAIL ERROR: " . $mailError);
  }

  /* ================= RESPONSE ================= */
  echo json_encode([
    "ok" => true,
    "message" => $sent
      ? "Registration successful. Please verify your email."
      : "Registered, but email failed to send.",
    "needs_verification" => true,
    "mail_sent" => $sent
  ]);

} catch (Throwable $e) {
  error_log("REGISTER ADMIN ERROR: " . $e->getMessage());

  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}