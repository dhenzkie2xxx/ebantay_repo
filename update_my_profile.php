<?php
require_once __DIR__ . "/require_account_user.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  auth_out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

$firstname = trim((string)($data["firstname"] ?? ""));
$lastname  = trim((string)($data["lastname"] ?? ""));
$username  = trim((string)($data["username"] ?? ""));
$email     = trim((string)($data["email"] ?? ""));

if ($firstname === "" || $lastname === "" || $username === "" || $email === "") {
  auth_out(400, ["ok" => false, "message" => "All fields are required."]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  auth_out(400, ["ok" => false, "message" => "Invalid email format."]);
}

if (strlen($username) < 3 || preg_match('/\s/', $username)) {
  auth_out(400, ["ok" => false, "message" => "Username must be at least 3 characters and contain no spaces."]);
}

try {
  $dup = $pdo->prepare("
    SELECT id
    FROM users
    WHERE (username = ? OR email = ?)
      AND id <> ?
    LIMIT 1
  ");
  $dup->execute([$username, $email, $AUTH_USER["id"]]);

  if ($dup->fetch()) {
    auth_out(409, ["ok" => false, "message" => "Username or email is already in use."]);
  }

  $stmt = $pdo->prepare("
    UPDATE users
    SET firstname = ?, lastname = ?, username = ?, email = ?
    WHERE id = ?
  ");
  $stmt->execute([
    $firstname,
    $lastname,
    $username,
    $email,
    $AUTH_USER["id"]
  ]);

  auth_out(200, [
    "ok" => true,
    "message" => "Profile updated successfully.",
    "user" => [
      "id" => $AUTH_USER["id"],
      "firstname" => $firstname,
      "lastname" => $lastname,
      "username" => $username,
      "email" => $email,
      "role" => $AUTH_USER["role"],
      "station_id" => $AUTH_USER["station_id"],
      "station_name" => $AUTH_USER["station_name"],
      "station_verification_status" => $AUTH_USER["station_verification_status"]
    ]
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}