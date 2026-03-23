<?php
require_once __DIR__ . "/require_account_user.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

try {
  $stmt = $pdo->prepare("
    UPDATE users
    SET
      profile_photo = NULL,
      profile_photo_mime = NULL,
      profile_photo_name = NULL
    WHERE id = ?
  ");
  $stmt->execute([$AUTH_USER["id"]]);

  auth_out(200, [
    "ok" => true,
    "message" => "Profile photo removed successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}