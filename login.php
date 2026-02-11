<?php
require_once __DIR__ . "/db.php";

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$username = isset($data["username"]) ? trim($data["username"]) : "";
$password = isset($data["password"]) ? $data["password"] : "";

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Fill required credentials"]);
  exit;
}

$stmt = $pdo->prepare("SELECT id, lastname, firstname, email, username, password_hash, role, is_email_verified
                       FROM users
                       WHERE username = ?
                       LIMIT 1");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user["password_hash"])) {
  http_response_code(401);
  echo json_encode(["ok" => false, "message" => "Invalid credentials"]);
  exit;
}

//Block if email not verified
if ((int)($user["is_email_verified"] ?? 0) !== 1) {
  http_response_code(403);
  echo json_encode([
    "ok" => false,
    "message" => "Please verify your email to continue.",
    "needs_verification" => true,
    "email" => $user["email"]
  ]);
  exit;
}

$token = bin2hex(random_bytes(24));

echo json_encode([
  "ok" => true,
  "message" => "Login successful",
  "token" => $token,
  "user" => [
    "id" => $user["id"],
    "lastname" => $user["lastname"],
    "firstname" => $user["firstname"],
    "username" => $user["username"],
    "role" => $user["role"]
  ]
]);
