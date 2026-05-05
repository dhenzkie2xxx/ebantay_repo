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
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

$policeUserId = $data["police_user_id"] ?? null;
$firstname = trim((string)($data["firstname"] ?? ""));
$lastname = trim((string)($data["lastname"] ?? ""));
$email = trim((string)($data["email"] ?? ""));
$username = trim((string)($data["username"] ?? ""));
$password = trim((string)($data["password"] ?? ""));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($policeUserId) || (int)$policeUserId <= 0) {
  out(400, ["ok" => false, "message" => "Invalid Police on Field user ID"]);
}

if ($firstname === "" || $lastname === "" || $email === "" || $username === "") {
  out(400, ["ok" => false, "message" => "First name, last name, email, and username are required."]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  out(400, ["ok" => false, "message" => "Invalid email address."]);
}

try {
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can edit Police on Field accounts."
    ]);
  }

  $policeUserId = (int)$policeUserId;
  $stationId = (int)$admin["station_id"];

  $oldStmt = $pdo->prepare("
    SELECT
      id,
      firstname,
      lastname,
      email,
      username,
      role,
      station_id,
      account_status,
      duty_status,
      valid
    FROM users
    WHERE id = ?
      AND role = 'police_on_field'
      AND station_id = ?
    LIMIT 1
  ");
  $oldStmt->execute([$policeUserId, $stationId]);
  $old = $oldStmt->fetch(PDO::FETCH_ASSOC);

  if (!$old) {
    out(404, [
      "ok" => false,
      "message" => "Police on Field account not found under your station."
    ]);
  }

  $dup = $pdo->prepare("
    SELECT id
    FROM users
    WHERE (email = ? OR username = ?)
      AND id <> ?
    LIMIT 1
  ");
  $dup->execute([$email, $username, $policeUserId]);

  if ($dup->fetch(PDO::FETCH_ASSOC)) {
    out(409, [
      "ok" => false,
      "message" => "Email or username already exists."
    ]);
  }

  $pdo->beginTransaction();

  if ($password !== "") {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $update = $pdo->prepare("
      UPDATE users
      SET
        firstname = ?,
        lastname = ?,
        email = ?,
        username = ?,
        password_hash = ?
      WHERE id = ?
        AND role = 'police_on_field'
        AND station_id = ?
    ");

    $update->execute([
      $firstname,
      $lastname,
      $email,
      $username,
      $hash,
      $policeUserId,
      $stationId
    ]);

    $passwordChanged = true;
  } else {
    $update = $pdo->prepare("
      UPDATE users
      SET
        firstname = ?,
        lastname = ?,
        email = ?,
        username = ?
      WHERE id = ?
        AND role = 'police_on_field'
        AND station_id = ?
    ");

    $update->execute([
      $firstname,
      $lastname,
      $email,
      $username,
      $policeUserId,
      $stationId
    ]);

    $passwordChanged = false;
  }

  write_audit_log(
    $pdo,
    $admin,
    "POLICE_ACCOUNT_UPDATED",
    "user",
    $policeUserId,
    "Station Admin updated a Police on Field account.",
    [
      "module" => "police_on_field",
      "target_user_id" => $policeUserId,
      "old_values" => [
        "firstname" => $old["firstname"],
        "lastname" => $old["lastname"],
        "email" => $old["email"],
        "username" => $old["username"]
      ],
      "new_values" => [
        "firstname" => $firstname,
        "lastname" => $lastname,
        "email" => $email,
        "username" => $username,
        "password_changed" => $passwordChanged
      ]
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Police on Field account updated successfully.",
    "police_user_id" => $policeUserId
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