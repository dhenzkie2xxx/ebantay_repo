<?php
header("Content-Type: application/json");

require_once __DIR__ . "/db.php";

$token = trim((string)($_GET["token"] ?? ""));

if ($token === "") {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "Missing token"
  ]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.role,
      u.valid,
      u.station_id,
      u.account_status,
      u.rejected_reason,
      u.is_email_verified,
      u.email_verify_expires,
      ps.station_name,
      ps.verification_status AS station_verification_status,
      ps.is_active AS station_is_active
    FROM users u
    LEFT JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.email_verify_token = ?
    LIMIT 1
  ");
  $stmt->execute([$token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "message" => "Invalid token"
    ]);
    exit;
  }

  if (
    empty($user["is_email_verified"]) &&
    (!empty($user["email_verify_expires"]) && strtotime($user["email_verify_expires"]) < time())
  ) {
    http_response_code(400);
    echo json_encode([
      "ok" => false,
      "code" => "TOKEN_EXPIRED",
      "message" => "Verification link expired"
    ]);
    exit;
  }

  if ((int)$user["is_email_verified"] !== 1) {
    $upd = $pdo->prepare("
      UPDATE users
      SET
        is_email_verified = 1,
        valid = 'valid',
        email_verify_token = NULL,
        email_verify_expires = NULL
      WHERE id = ?
    ");
    $upd->execute([$user["id"]]);
  }

  $authToken = bin2hex(random_bytes(32));
  $authExpires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 7);

  $saveToken = $pdo->prepare("
    UPDATE users
    SET api_token = ?, api_token_expires = ?
    WHERE id = ?
  ");
  $saveToken->execute([$authToken, $authExpires, $user["id"]]);

  $onboardingOnly = false;
  $code = null;
  $message = "Email verified successfully";

  if ($user["role"] === "admin") {
    $stationStatus = $user["station_verification_status"] ?? null;
    $stationActive = (int)($user["station_is_active"] ?? 0);
    $accountStatus = $user["account_status"] ?? "pending";

    if ($accountStatus === "disabled") {
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "code" => "ACCOUNT_DISABLED",
        "message" => "Your admin account is disabled."
      ]);
      exit;
    }

    $onboardingOnly = true;
    $code = "STATION_INCOMPLETE";
    $message = "Email verified. Please complete your station onboarding.";

    if (empty($user["station_id"])) {
      $code = "STATION_NOT_REGISTERED";
      $message = "Email verified. No police station is linked to this admin account yet.";
    } elseif ($stationStatus === "approved" && $stationActive === 1 && $accountStatus === "active") {
      $onboardingOnly = false;
      $code = null;
      $message = "Email verified and account is ready.";
    } elseif ($stationStatus === "pending" || $stationStatus === "under_review") {
      $code = "STATION_PENDING";
      $message = "Email verified. Your police station account is pending verification.";
    } elseif ($stationStatus === "rejected") {
      $code = "STATION_REJECTED";
      $message = "Email verified. Your police station registration was rejected.";
    } elseif ($stationStatus === "resubmission_required") {
      $code = "STATION_RESUBMIT";
      $message = "Email verified. Your police station registration requires resubmission.";
    }
  }

  echo json_encode([
    "ok" => true,
    "message" => $message,
    "code" => $code,
    "token" => $authToken,
    "token_expires" => $authExpires,
    "onboarding_only" => $onboardingOnly,
    "user" => [
      "id" => (int)$user["id"],
      "firstname" => $user["firstname"],
      "lastname" => $user["lastname"],
      "email" => $user["email"],
      "username" => $user["username"],
      "role" => $user["role"],
      "station_id" => $user["station_id"] ? (int)$user["station_id"] : null,
      "station_name" => $user["station_name"] ?? null,
      "station_verification_status" => $user["station_verification_status"] ?? null,
      "account_status" => $user["account_status"] ?? null
    ],
    "rejected_reason" => $user["rejected_reason"] ?? null
  ]);
} catch (Throwable $e) {
  error_log("VERIFY_EMAIL ERROR: " . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error"
  ]);
}
?>