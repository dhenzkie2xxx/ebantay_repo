<?php
header("Content-Type: application/json");

require_once __DIR__ . "/db.php";

$token = $_GET["token"] ?? "";

if (!$token) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Missing token"]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id, email_verify_expires, is_email_verified
    FROM users
    WHERE email_verify_token = ?
    LIMIT 1
  ");
  $stmt->execute([$token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    http_response_code(400);
    echo json_encode(["ok" => false, "message" => "Invalid token"]);
    exit;
  }

  if ((int)$user["is_email_verified"] === 1) {
    echo json_encode([
      "ok" => true,
      "message" => "Email already verified"
    ]);
    exit;
  }

  if (strtotime($user["email_verify_expires"]) < time()) {
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "code" => "TOKEN_EXPIRED",
      "message" => "Verification link expired"
    ]);
    exit;
  }

  // ✅ Verify email
  $upd = $pdo->prepare("
    UPDATE users
    SET is_email_verified = 1,
        valid = 'valid',
        email_verify_token = NULL,
        email_verify_expires = NULL
    WHERE id = ?
  ");
  $upd->execute([$user["id"]]);

  echo json_encode([
    "ok" => true,
    "message" => "Email verified successfully"
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error"
  ]);
}