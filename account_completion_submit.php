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
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}

function requirement_applies_to_user(PDO $pdo, array $requirement, array $profile): bool {
  $reqStationId = $requirement["station_id"] !== null ? (int)$requirement["station_id"] : null;
  $reqProvince = normalize_scope_value($requirement["province"] ?? null);
  $reqCity = normalize_scope_value($requirement["city_municipality"] ?? null);

  $userProvince = normalize_scope_value($profile["province"] ?? null);
  $userCity = normalize_scope_value($profile["city_municipality"] ?? null);

  // global requirement
  if ($reqStationId === null && $reqProvince === null && $reqCity === null) {
    return true;
  }

  // province/city scoped
  if ($reqStationId === null) {
    if (!$userProvince || !$userCity || !$reqProvince || !$reqCity) return false;

    return
      strcasecmp($userProvince, $reqProvince) === 0 &&
      strcasecmp($userCity, $reqCity) === 0;
  }

  // station scoped
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

  $userId = (int)$user["id"];

  if ((int)($user["is_email_verified"] ?? 0) !== 1) {
    out(403, ["ok" => false, "message" => "Please verify your email first"]);
  }

  $profile = get_user_profile($pdo, $userId);
  if (!$profile) {
    out(422, [
      "ok" => false,
      "message" => "Please complete your account profile before submitting for verification"
    ]);
  }

  // Minimal profile checks
  $mobileNumber = trim((string)($profile["mobile_number"] ?? ""));
  $addressText = trim((string)($profile["address_text"] ?? ""));
  $addressLat = $profile["address_lat"];
  $addressLng = $profile["address_lng"];
  $province = normalize_scope_value($profile["province"] ?? null);
  $city = normalize_scope_value($profile["city_municipality"] ?? null);

  if ($mobileNumber === "" || strlen($mobileNumber) < 10) {
    out(422, ["ok" => false, "message" => "Mobile number is required"]);
  }

  if ($addressText === "" || $addressLat === null || $addressLng === null) {
    out(422, ["ok" => false, "message" => "Pinned address is required"]);
  }

  if (!$province || !$city) {
    out(422, [
      "ok" => false,
      "message" => "Profile province and city/municipality are required before submission"
    ]);
  }

  // Load all active requirements for user scope
  $requirementsStmt = $pdo->query("
    SELECT
      r.id,
      r.requirement_code,
      r.requirement_name,
      r.is_required,
      r.is_system,
      r.station_id,
      r.city_municipality,
      r.province,
      r.active
    FROM user_verification_requirements r
    WHERE r.active = 1
    ORDER BY r.is_system DESC, r.requirement_name ASC
  ");

  $allRequirements = $requirementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $applicableRequirements = [];

  foreach ($allRequirements as $req) {
    if (requirement_applies_to_user($pdo, $req, $profile)) {
      $applicableRequirements[] = $req;
    }
  }

  if (count($applicableRequirements) === 0) {
    out(422, [
      "ok" => false,
      "message" => "No verification requirements are configured for your account scope yet"
    ]);
  }

  // Load latest submissions by requirement
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

  $latestSubmissionByRequirement = [];
  foreach ($subRows as $s) {
    $reqId = (int)$s["requirement_id"];
    if (!isset($latestSubmissionByRequirement[$reqId])) {
      $latestSubmissionByRequirement[$reqId] = $s;
    }
  }

  $missingRequired = [];
  foreach ($applicableRequirements as $req) {
    $reqId = (int)$req["id"];
    $isRequired = (int)($req["is_required"] ?? 0) === 1;

    if (!$isRequired) {
      continue;
    }

    if (!isset($latestSubmissionByRequirement[$reqId])) {
      $missingRequired[] = [
        "id" => $reqId,
        "code" => $req["requirement_code"],
        "name" => $req["requirement_name"],
      ];
    }
  }

  if (!empty($missingRequired)) {
    out(422, [
      "ok" => false,
      "message" => "Please upload all required documents before submitting",
      "missing_requirements" => $missingRequired
    ]);
  }

  $pdo->beginTransaction();

  // Update main user status:
  // keep user blocked until approved
  $updateUserStmt = $pdo->prepare("
    UPDATE users
    SET
      valid = 'unvalid',
      account_status = 'pending',
      rejected_reason = NULL,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $updateUserStmt->execute([$userId]);

  // Update latest verification request if it's still open-ish; otherwise insert new
  $latestReqStmt = $pdo->prepare("
    SELECT
      id,
      status
    FROM user_verification_requests
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $latestReqStmt->execute([$userId]);
  $latestReq = $latestReqStmt->fetch(PDO::FETCH_ASSOC);

  if ($latestReq) {
    $latestReqId = (int)$latestReq["id"];

    $updateReqStmt = $pdo->prepare("
      UPDATE user_verification_requests
      SET
        status = 'pending',
        submitted_at = NOW(),
        reviewed_at = NULL,
        reviewed_by = NULL,
        remarks = NULL
      WHERE id = ?
    ");
    $updateReqStmt->execute([$latestReqId]);

    $verificationRequestId = $latestReqId;
  } else {
    $insertReqStmt = $pdo->prepare("
      INSERT INTO user_verification_requests
      (
        user_id,
        status,
        submitted_at,
        reviewed_at,
        reviewed_by,
        remarks
      )
      VALUES (?, 'pending', NOW(), NULL, NULL, NULL)
    ");
    $insertReqStmt->execute([$userId]);

    $verificationRequestId = (int)$pdo->lastInsertId();
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Account submitted for verification successfully",
    "user" => [
      "id" => $userId,
      "account_status" => "pending",
      "valid" => "unvalid",
    ],
    "verification_request" => [
      "id" => $verificationRequestId,
      "status" => "pending",
      "submitted_at" => date("Y-m-d H:i:s"),
    ],
    "completion" => [
      "required_total" => count(array_filter($applicableRequirements, function ($r) {
        return (int)($r["is_required"] ?? 0) === 1;
      })),
      "required_submitted" => count(array_filter($applicableRequirements, function ($r) use ($latestSubmissionByRequirement) {
        $reqId = (int)$r["id"];
        return (int)($r["is_required"] ?? 0) === 1 && isset($latestSubmissionByRequirement[$reqId]);
      })),
      "is_ready_for_submission" => true
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