<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/mailer.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

// accept either email or username
$emailOrUsername = trim($data["email"] ?? $data["username"] ?? "");

if ($emailOrUsername === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Email or username is required"]);
  exit;
}

// Find user (by email OR username)
$stmt = $pdo->prepare("
  SELECT id, firstname, lastname, email, username, is_email_verified
  FROM users
  WHERE email = ? OR username = ?
  LIMIT 1
");
$stmt->execute([$emailOrUsername, $emailOrUsername]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  // don't leak whether an account exists (good security)
  http_response_code(200);
  echo json_encode([
    "ok" => true,
    "message" => "If the account exists, a verification email has been sent."
  ]);
  exit;
}

if ((int)$user["is_email_verified"] === 1) {
  http_response_code(409);
  echo json_encode(["ok" => false, "message" => "Email is already verified. Please login."]);
  exit;
}

// Generate new token + expiry (24 hours)
$token = bin2hex(random_bytes(32));
$expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24);

// Save to DB
$upd = $pdo->prepare("
  UPDATE users
  SET email_verify_token = ?, email_verify_expires = ?
  WHERE id = ?
");
$upd->execute([$token, $expires, $user["id"]]);

// Build verification link
// Prefer your custom API domain if you set it in Render as APP_URL, otherwise fallback
$appUrl = getenv("APP_URL") ?: "https://ebantay.top.gen.in";
$verifyLink = rtrim($appUrl, "/") . "/verify.php?token=" . $token;

// Send email
$fullName = trim($user["firstname"] . " " . $user["lastname"]);
$sent = sendVerificationEmail($user["email"], $fullName ?: $user["username"], $verifyLink);

if (!$sent) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Failed to send verification email. Please try again later."]);
  exit;
}

echo json_encode([
  "ok" => true,
  "message" => "Verification email sent. Please check your inbox.",
  "email" => $user["email"]
]);
?>