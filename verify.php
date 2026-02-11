<?php
require_once __DIR__ . "/db.php";

$token = trim($_GET["token"] ?? "");

if ($token === "" || strlen($token) < 40) {
  http_response_code(400);
  echo "Invalid token.";
  exit;
}

// Find user by token
$stmt = $pdo->prepare("SELECT id, is_email_verified, email_verify_expires FROM users WHERE email_verify_token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
  http_response_code(404);
  echo "Token not found.";
  exit;
}

if ((int)$user["is_email_verified"] === 1) {
  echo "Email already verified. You can close this page and login.";
  exit;
}

// Check expiry
if (!empty($user["email_verify_expires"]) && strtotime($user["email_verify_expires"]) < time()) {
  http_response_code(410);
  echo "Verification link expired. Please request a new one.";
  exit;
}

// Verify email
$upd = $pdo->prepare("UPDATE users
  SET is_email_verified = 1,
      email_verify_token = NULL,
      email_verify_expires = NULL
  WHERE id = ?"
);
$upd->execute([(int)$user["id"]]);

echo "Email verified successfully! You may now return to the app and login.";
