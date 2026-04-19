<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
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

function get_user_profile(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("
    SELECT
      user_id,
      mobile_number,
      address_text,
      address_lat,
      address_lng,
      barangay,
      city_municipality,
      province,
      region
    FROM user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function requirement_applies_to_user(PDO $pdo, array $requirement, array $profile): bool {
  $reqStationId = $requirement["station_id"] !== null ? (int)$requirement["station_id"] : null;
  $reqProvince = normalize_scope_value($requirement["province"] ?? null);
  $reqCity = normalize_scope_value($requirement["city_municipality"] ?? null);

  $userProvince = normalize_scope_value($profile["province"] ?? null);
  $userCity = normalize_scope_value($profile["city_municipality"] ?? null);

  if ($reqStationId === null && $reqProvince === null && $reqCity === null) {
    return true;
  }

  if ($reqStationId === null) {
    if (!$userProvince || !$userCity || !$reqProvince || !$reqCity) return false;

    return
      strcasecmp($userProvince, $reqProvince) === 0 &&
      strcasecmp($userCity, $reqCity) === 0;
  }

  $stmt = $pdo->prepare("
    SELECT
      province,
      city_municipality
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$reqStationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) return false;

  $stationProvince = normalize_scope_value($station["province"] ?? null);
  $stationCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$userProvince || !$userCity || !$stationProvince || !$stationCity) return false;

  return
    strcasecmp($userProvince, $stationProvince) === 0 &&
    strcasecmp($userCity, $stationCity) === 0;
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
    out(403, ["ok" => false, "message" => "Only citizen users can submit account completion"]);
  }

  if (strtolower((string)($user["account_flag_status"] ?? "none")) === "suspended") {
    out(403, [
      "ok" => false,
      "message" => "Your account is suspended. Please contact the station admin."
    ]);
  }

  $currentStatus = strtolower((string)($user["account_status"] ?? "pending"));
  if (in_array($currentStatus, ["verified", "active", "disabled"], true)) {
    out(403, ["ok" => false, "message" => "Your account is already locked or verified"]);
  }

  $userId = (int)$user["id"];

  $profile = get_user_profile($pdo, $userId);
  if (!$profile) {
    out(422, ["ok" => false, "message" => "Please save your profile first"]);
  }

  $missing = [];

  if (!is_valid_ph_mobile((string)($profile["mobile_number"] ?? ""))) {
    $missing[] = ["code" => "MOBILE_NUMBER", "name" => "Valid Mobile Number"];
  }
  if (trim((string)($profile["address_text"] ?? "")) === "") {
    $missing[] = ["code" => "ADDRESS_TEXT", "name" => "Address / Landmark"];
  }
  if (!is_numeric($profile["address_lat"] ?? null) || !is_numeric($profile["address_lng"] ?? null)) {
    $missing[] = ["code" => "ADDRESS_PIN", "name" => "Pinned Address"];
  }
  if (trim((string)($profile["city_municipality"] ?? "")) === "") {
    $missing[] = ["code" => "CITY", "name" => "City / Municipality"];
  }
  if (trim((string)($profile["province"] ?? "")) === "") {
    $missing[] = ["code" => "PROVINCE", "name" => "Province"];
  }

  $requirementsStmt = $pdo->query("
    SELECT
      id,
      requirement_code,
      requirement_name,
      is_required,
      is_system,
      station_id,
      city_municipality,
      province,
      active
    FROM user_verification_requirements
    WHERE active = 1
    ORDER BY is_system DESC, requirement_name ASC
  ");
  $allRequirements = $requirementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $applicableRequirements = [];
  foreach ($allRequirements as $req) {
    if (requirement_applies_to_user($pdo, $req, $profile)) {
      $applicableRequirements[] = $req;
    }
  }

  if (!empty($applicableRequirements)) {
    $subStmt = $pdo->prepare("
      SELECT
        s.id,
        s.requirement_id,
        s.status,
        s.uploaded_at
      FROM user_requirement_submissions s
      WHERE s.user_id = ?
      ORDER BY s.requirement_id ASC, s.uploaded_at DESC, s.id DESC
    ");
    $subStmt->execute([$userId]);
    $subRows = $subStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $latestByRequirement = [];
    foreach ($subRows as $s) {
      $reqId = (int)$s["requirement_id"];
      if (!isset($latestByRequirement[$reqId])) {
        $latestByRequirement[$reqId] = $s;
      }
    }

    foreach ($applicableRequirements as $req) {
      if ((int)($req["is_required"] ?? 0) !== 1) {
        continue;
      }

      $reqId = (int)$req["id"];
      $submission = $latestByRequirement[$reqId] ?? null;

      if (!$submission) {
        $missing[] = [
          "code" => $req["requirement_code"],
          "name" => $req["requirement_name"],
        ];
        continue;
      }

      $subStatus = strtolower((string)($submission["status"] ?? ""));
      if (!in_array($subStatus, ["submitted", "approved"], true)) {
        $missing[] = [
          "code" => $req["requirement_code"],
          "name" => $req["requirement_name"],
        ];
      }
    }
  }

  if (!empty($missing)) {
    out(422, [
      "ok" => false,
      "message" => "Please complete all required profile fields and upload all required documents before submitting.",
      "missing_requirements" => $missing,
    ]);
  }

  $pdo->beginTransaction();

  $latestReqStmt = $pdo->prepare("
    SELECT id, status
    FROM user_verification_requests
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $latestReqStmt->execute([$userId]);
  $latestReq = $latestReqStmt->fetch(PDO::FETCH_ASSOC);

  if ($latestReq && in_array(strtolower((string)$latestReq["status"]), ["pending", "submitted"], true)) {
    $verificationRequestId = (int)$latestReq["id"];
  } else {
    $insertReqStmt = $pdo->prepare("
      INSERT INTO user_verification_requests
      (
        user_id,
        status,
        submitted_at,
        remarks
      )
      VALUES (?, 'pending', NOW(), NULL)
    ");
    $insertReqStmt->execute([$userId]);
    $verificationRequestId = (int)$pdo->lastInsertId();
  }

  $userStatusStmt = $pdo->prepare("
    UPDATE users
    SET
      account_status = 'pending',
      rejected_reason = NULL,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $userStatusStmt->execute([$userId]);

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Account verification submitted successfully",
    "verification_request" => [
      "id" => $verificationRequestId,
      "user_id" => $userId,
      "status" => "pending",
    ],
    "user" => [
      "id" => $userId,
      "account_status" => "pending",
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