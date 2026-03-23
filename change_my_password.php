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

$currentPassword = (string)($data["current_password"] ?? "");
$newPassword = (string)($data["new_password"] ?? "");
$confirmPassword = (string)($data["confirm_password"] ?? "");

if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
  auth_out(400, ["ok" => false, "message" => "All password fields are required."]);
}

if ($newPassword !== $confirmPassword) {
  auth_out(400, ["ok" => false, "message" => "New password and confirm password do not match."]);
}

if (strlen($newPassword) < 6) {
  auth_out(400, ["ok" => false, "message" => "New password must be at least 6 characters."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT password_hash
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$AUTH_USER["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    auth_out(404, ["ok" => false, "message" => "Account not found."]);
  }

  if (!password_verify($currentPassword, $row["password_hash"])) {
    auth_out(400, ["ok" => false, "message" => "Current password is incorrect."]);
  }

  if (password_verify($newPassword, $row["password_hash"])) {
    auth_out(400, ["ok" => false, "message" => "New password must be different from your current password."]);
  }

  $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

  $upd = $pdo->prepare("
    UPDATE users
    SET password_hash = ?
    WHERE id = ?
  ");
  $upd->execute([$newHash, $AUTH_USER["id"]]);

  auth_out(200, [
    "ok" => true,
    "message" => "Password updated successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}