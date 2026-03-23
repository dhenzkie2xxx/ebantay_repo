<?php
require_once __DIR__ . "/require_account_user.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->prepare("
    SELECT
      id,
      firstname,
      lastname,
      username,
      email,
      role,
      station_id,
      account_status,
      is_email_verified,
      profile_photo_mime,
      profile_photo_name,
      updated_at
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$AUTH_USER["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    auth_out(404, ["ok" => false, "message" => "Account not found."]);
  }

  auth_out(200, [
    "ok" => true,
    "account" => [
      "id" => (int)$row["id"],
      "firstname" => $row["firstname"],
      "lastname" => $row["lastname"],
      "username" => $row["username"],
      "email" => $row["email"],
      "role" => $row["role"],
      "station_id" => $row["station_id"] ? (int)$row["station_id"] : null,
      "account_status" => $row["account_status"],
      "is_email_verified" => (int)$row["is_email_verified"],
      "profile_photo_mime" => $row["profile_photo_mime"],
      "profile_photo_name" => $row["profile_photo_name"],
      "profile_photo_url" => "/api/get_my_profile_photo.php?token=" . urlencode(bearer_token()),
      "updated_at" => $row["updated_at"]
    ]
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}