<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$token = bearer_token();

if (!$token) {
  out(401, [
    "ok" => false,
    "message" => "Missing token"
  ]);
}

try {
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, [
      "ok" => false,
      "message" => "Unauthorized"
    ]);
  }

  if (auth_check_token_expired($user)) {
    out(401, [
      "ok" => false,
      "message" => "Token expired"
    ]);
  }

  $gate = auth_admin_station_gate($user);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($user["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can create Police on Field accounts."
    ]);
  }

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) {
    out(400, [
      "ok" => false,
      "message" => "Invalid JSON body"
    ]);
  }

  $firstname = trim((string)($data["firstname"] ?? ""));
  $lastname  = trim((string)($data["lastname"] ?? ""));
  $email     = trim((string)($data["email"] ?? ""));
  $username  = trim((string)($data["username"] ?? ""));
  $password  = trim((string)($data["password"] ?? ""));

  if (
    $firstname === "" ||
    $lastname === "" ||
    $email === "" ||
    $username === "" ||
    $password === ""
  ) {
    out(400, [
      "ok" => false,
      "message" => "All fields required."
    ]);
  }

  $chk = $pdo->prepare("
    SELECT id
    FROM users
    WHERE username = ?
       OR email = ?
    LIMIT 1
  ");
  $chk->execute([$username, $email]);

  if ($chk->fetch()) {
    out(409, [
      "ok" => false,
      "message" => "Username or email already exists."
    ]);
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    INSERT INTO users (
      lastname,
      firstname,
      email,
      username,
      password_hash,
      role,
      station_id,
      valid,
      account_status,
      is_email_verified,
      approved_by,
      approved_at
    )
    VALUES (
      ?, ?, ?, ?, ?, ?,
      ?, 'valid',
      'active',
      1,
      ?,
      NOW()
    )
  ");

  $stmt->execute([
    $lastname,
    $firstname,
    $email,
    $username,
    $hash,
    "police_on_field",
    (int)$user["station_id"],
    (int)$user["id"]
  ]);

  $newId = (int)$pdo->lastInsertId();

  $profile = $pdo->prepare("
    INSERT INTO user_profiles (
      user_id,
      city_municipality,
      province,
      region
    )
    VALUES (?, ?, ?, ?)
  ");

  $profile->execute([
    $newId,
    $user["station_city_municipality"] ?? null,
    $user["station_province"] ?? null,
    $user["station_region"] ?? null
  ]);

  write_audit_log(
    $pdo,
    $user,
    "POLICE_ACCOUNT_CREATED",
    "user",
    $newId,
    "Station Admin created a Police on Field account.",
    [
      "module" => "police_on_field",
      "target_user_id" => $newId,
      "new_values" => [
        "id" => $newId,
        "firstname" => $firstname,
        "lastname" => $lastname,
        "email" => $email,
        "username" => $username,
        "role" => "police_on_field",
        "station_id" => (int)$user["station_id"],
        "valid" => "valid",
        "account_status" => "active",
        "is_email_verified" => 1,
        "approved_by" => (int)$user["id"],
        "profile" => [
          "city_municipality" => $user["station_city_municipality"] ?? null,
          "province" => $user["station_province"] ?? null,
          "region" => $user["station_region"] ?? null
        ]
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Police on Field account created successfully.",
    "user_id" => $newId
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}