<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_text($value): string {
  return trim((string)($value ?? ""));
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function get_bearer_or_body_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $raw = file_get_contents("php://input");
  if ($raw !== "") {
    $json = json_decode($raw, true);
    if (is_array($json)) {
      $bodyToken = trim((string)($json["token"] ?? ""));
      if ($bodyToken !== "") return $bodyToken;
    }
  }

  return "";
}

function get_request_json(): array {
  $raw = file_get_contents("php://input");
  if ($raw === "") return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

function is_valid_ph_mobile(string $value): bool {
  return preg_match('/^09\d{9}$/', $value) === 1;
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_body_token();
  if ($token === "") {
    out(401, ["ok" => false, "message" => "Missing token"]);
  }

  $user = auth_get_user_by_token($pdo, $token);
  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if (strtolower((string)($user["role"] ?? "")) !== "citizen") {
    out(403, ["ok" => false, "message" => "Only citizen users can save account profile"]);
  }

  if (strtolower((string)($user["account_flag_status"] ?? "none")) === "suspended") {
    out(403, [
      "ok" => false,
      "message" => "Your account is suspended. Please contact the station admin."
    ]);
  }

  $currentStatus = strtolower((string)($user["account_status"] ?? "pending"));
  if (in_array($currentStatus, ["verified", "active", "disabled"], true)) {
    out(403, [
      "ok" => false,
      "message" => "Your account information is already locked."
    ]);
  }

  $data = get_request_json();
  $userId = (int)$user["id"];

  $mobileNumber = normalize_text($data["mobile_number"] ?? "");
  $addressText = normalize_text($data["address_text"] ?? "");
  $barangay = normalize_scope_value($data["barangay"] ?? null);
  $cityMunicipality = normalize_scope_value($data["city_municipality"] ?? null);
  $province = normalize_scope_value($data["province"] ?? null);
  $region = normalize_scope_value($data["region"] ?? null);

  $addressLat = $data["address_lat"] ?? null;
  $addressLng = $data["address_lng"] ?? null;

  if (!is_valid_ph_mobile($mobileNumber)) {
    out(422, [
      "ok" => false,
      "message" => "Mobile number must be a valid Philippine mobile number in this format: 09XXXXXXXXX"
    ]);
  }

  if ($addressText === "") {
    out(422, ["ok" => false, "message" => "Address text is required"]);
  }

  if ($addressLat === null || $addressLng === null || !is_numeric($addressLat) || !is_numeric($addressLng)) {
    out(422, ["ok" => false, "message" => "Pinned address coordinates are required"]);
  }

  $addressLat = (float)$addressLat;
  $addressLng = (float)$addressLng;

  if ($addressLat < -90 || $addressLat > 90 || $addressLng < -180 || $addressLng > 180) {
    out(422, ["ok" => false, "message" => "Address coordinates are out of range"]);
  }

  if (!$cityMunicipality || !$province) {
    out(422, ["ok" => false, "message" => "City/Municipality and Province are required"]);
  }

  $profileStmt = $pdo->prepare("
    SELECT
      id,
      mobile_number
    FROM user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $profileStmt->execute([$userId]);
  $existingProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

  if ($existingProfile && trim((string)($existingProfile["mobile_number"] ?? "")) !== "") {
    $existingMobile = trim((string)$existingProfile["mobile_number"]);
    if ($existingMobile !== $mobileNumber) {
      out(403, [
        "ok" => false,
        "message" => "Your mobile number is already locked and can no longer be changed."
      ]);
    }
  }

  $duplicateStmt = $pdo->prepare("
    SELECT up.user_id
    FROM user_profiles up
    WHERE up.mobile_number = ?
      AND up.user_id <> ?
    LIMIT 1
  ");
  $duplicateStmt->execute([$mobileNumber, $userId]);
  $duplicateUserId = $duplicateStmt->fetchColumn();

  if ($duplicateUserId) {
    out(422, [
      "ok" => false,
      "message" => "This mobile number is already used by another user."
    ]);
  }

  $pdo->beginTransaction();

  if ($existingProfile) {
    $updateStmt = $pdo->prepare("
      UPDATE user_profiles
      SET
        mobile_number = ?,
        address_text = ?,
        address_lat = ?,
        address_lng = ?,
        barangay = ?,
        city_municipality = ?,
        province = ?,
        region = ?,
        updated_at = NOW()
      WHERE user_id = ?
      LIMIT 1
    ");
    $updateStmt->execute([
      $mobileNumber,
      $addressText,
      $addressLat,
      $addressLng,
      $barangay,
      $cityMunicipality,
      $province,
      $region,
      $userId,
    ]);
  } else {
    $insertStmt = $pdo->prepare("
      INSERT INTO user_profiles
      (
        user_id,
        mobile_number,
        address_text,
        address_lat,
        address_lng,
        barangay,
        city_municipality,
        province,
        region,
        created_at,
        updated_at
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $insertStmt->execute([
      $userId,
      $mobileNumber,
      $addressText,
      $addressLat,
      $addressLng,
      $barangay,
      $cityMunicipality,
      $province,
      $region,
    ]);
  }

  if (in_array($currentStatus, ["rejected", "resubmission_required"], true)) {
    $statusStmt = $pdo->prepare("
      UPDATE users
      SET
        account_status = 'incomplete',
        rejected_reason = NULL,
        updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $statusStmt->execute([$userId]);
    $nextStatus = "incomplete";
  } else {
    $nextStatus = $currentStatus;
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Account profile saved successfully",
    "user" => [
      "id" => $userId,
      "account_status" => $nextStatus,
    ],
    "profile" => [
      "mobile_number" => $mobileNumber,
      "address_text" => $addressText,
      "address_lat" => $addressLat,
      "address_lng" => $addressLng,
      "barangay" => $barangay,
      "city_municipality" => $cityMunicipality,
      "province" => $province,
      "region" => $region,
      "mobile_locked" => true,
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}