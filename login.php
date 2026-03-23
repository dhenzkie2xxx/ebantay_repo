<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$username = isset($data["username"]) ? trim($data["username"]) : "";
$password = isset($data["password"]) ? (string)$data["password"] : "";

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Fill required credentials"]);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.lastname,
      u.firstname,
      u.email,
      u.username,
      u.password_hash,
      u.role,
      u.valid,
      u.is_email_verified,
      u.station_id,
      u.account_status,
      u.rejected_reason,
      ps.station_name,
      ps.verification_status AS station_verification_status,
      ps.is_active AS station_is_active
    FROM users u
    LEFT JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.username = ?
    LIMIT 1
  ");
  $stmt->execute([$username]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user || !password_verify($password, $user["password_hash"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "message" => "Invalid credentials"]);
    exit;
  }

  if ((int)($user["is_email_verified"] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "code" => "EMAIL_NOT_VERIFIED",
      "message" => "Please verify your email to continue.",
      "needs_verification" => true,
      "email" => $user["email"]
    ]);
    exit;
  }

  if ($user["role"] !== "citizen" && $user["role"] !== "admin" && $user["role"] !== "super_admin") {
    http_response_code(403);
    echo json_encode([
      "ok" => false,
      "message" => "Role not allowed."
    ]);
    exit;
  }

  if ($user["role"] === "super_admin") {
    if (($user["valid"] ?? "unvalid") !== "valid") {
      http_response_code(403);
      echo json_encode([
        "ok" => false,
        "code" => "ACCOUNT_NOT_VALID",
        "message" => "Super admin account is not valid."
      ]);
      exit;
    }
  }

  // citizen login remains normal
  if ($user["role"] === "citizen") {
    $token = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 7);

    $upd = $pdo->prepare("UPDATE users SET api_token = ?, api_token_expires = ? WHERE id = ?");
    $upd->execute([$token, $expires, $user["id"]]);

    echo json_encode([
      "ok" => true,
      "message" => "Login successful",
      "token" => $token,
      "token_expires" => $expires,
      "onboarding_only" => false,
      "user" => [
        "id" => (int)$user["id"],
        "lastname" => $user["lastname"],
        "firstname" => $user["firstname"],
        "email" => $user["email"],
        "username" => $user["username"],
        "role" => $user["role"]
      ]
    ]);
    exit;
  }

  $token = bin2hex(random_bytes(32));
  $expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 7);

  $upd = $pdo->prepare("UPDATE users SET api_token = ?, api_token_expires = ? WHERE id = ?");
  $upd->execute([$token, $expires, $user["id"]]);

  // admin onboarding logic
  if ($user["role"] === "admin") {
    $stationStatus = $user["station_verification_status"] ?? null;
    $stationActive = (int)($user["station_is_active"] ?? 0);
    $accountStatus = $user["account_status"] ?? "pending";

    // hard block only disabled admin accounts
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
    $message = "Your police station registration is incomplete.";

    if (empty($user["station_id"])) {
      $code = "STATION_NOT_REGISTERED";
      $message = "No police station is linked to this admin account yet.";
    } elseif ($stationStatus === "approved" && $stationActive === 1 && $accountStatus === "active") {
      $onboardingOnly = false;
      $code = null;
      $message = "Login successful";
    } elseif ($stationStatus === "pending" || $stationStatus === "under_review") {
      $code = "STATION_PENDING";
      $message = "Your police station account is pending verification.";
    } elseif ($stationStatus === "rejected") {
      $code = "STATION_REJECTED";
      $message = "Your police station registration was rejected.";
    } elseif ($stationStatus === "resubmission_required") {
      $code = "STATION_RESUBMIT";
      $message = "Your police station registration requires resubmission.";
    } elseif ($stationStatus === "draft" || $stationStatus === null) {
      $code = "STATION_INCOMPLETE";
      $message = "Your police station registration is incomplete.";
    } else {
      $code = "STATION_NOT_APPROVED";
      $message = "Your police station is not approved yet.";
    }

    echo json_encode([
      "ok" => true,
      "message" => $message,
      "code" => $code,
      "token" => $token,
      "token_expires" => $expires,
      "onboarding_only" => $onboardingOnly,
      "user" => [
        "id" => (int)$user["id"],
        "lastname" => $user["lastname"],
        "firstname" => $user["firstname"],
        "email" => $user["email"],
        "username" => $user["username"],
        "role" => $user["role"],
        "station_id" => $user["station_id"] ? (int)$user["station_id"] : null,
        "station_name" => $user["station_name"],
        "station_verification_status" => $stationStatus,
        "account_status" => $accountStatus
      ],
      "rejected_reason" => $user["rejected_reason"] ?? null
    ]);
    exit;
  }

  // super admin normal login
  echo json_encode([
    "ok" => true,
    "message" => "Login successful",
    "token" => $token,
    "token_expires" => $expires,
    "onboarding_only" => false,
    "user" => [
      "id" => (int)$user["id"],
      "lastname" => $user["lastname"],
      "firstname" => $user["firstname"],
      "email" => $user["email"],
      "username" => $user["username"],
      "role" => $user["role"],
      "station_id" => $user["station_id"] ? (int)$user["station_id"] : null,
      "station_name" => $user["station_name"],
      "station_verification_status" => $user["station_verification_status"]
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}