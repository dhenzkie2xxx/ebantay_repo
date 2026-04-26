<?php
require_once __DIR__ . "/cors.php";
require_once __DIR__ . "/db.php";

function auth_out(int $code, array $payload): void {
  http_response_code($code);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($payload);
  exit;
}

function bearer_token(): string {
  $h =
    $_SERVER["HTTP_AUTHORIZATION"] ??
    $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ??
    "";

  if ($h === "" && function_exists("getallheaders")) {
    $headers = getallheaders();
    if (isset($headers["Authorization"])) {
      $h = $headers["Authorization"];
    } elseif (isset($headers["authorization"])) {
      $h = $headers["authorization"];
    }
  }

  if (!$h) return "";
  if (stripos($h, "Bearer ") !== 0) return "";
  return trim(substr($h, 7));
}

function auth_get_user_by_token(PDO $pdo, string $token): ?array {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.lastname,
      u.firstname,
      u.email,
      u.username,
      u.role,
      u.valid,
      u.is_email_verified,
      u.api_token,
      u.api_token_expires,
      u.station_id,
      u.account_status,
      u.duty_status,
      u.last_seen_at,
      u.account_flag_status,
      u.false_report_count,
      u.false_alarm_count,
      u.flagged_reason,
      u.flagged_at,
      u.suspended_at,
      u.suspension_reason,
      u.approved_by,
      u.approved_at,
      u.rejected_reason,

      ps.station_name,
      ps.station_code,
      ps.station_type,
      ps.region AS station_region,
      ps.province AS station_province,
      ps.city_municipality AS station_city_municipality,
      ps.barangay AS station_barangay,
      ps.full_address AS station_full_address,
      ps.lat AS station_lat,
      ps.lng AS station_lng,
      ps.verification_status AS station_verification_status,
      ps.is_active AS station_is_active

    FROM users u
    LEFT JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.api_token = ?
    LIMIT 1
  ");
  $stmt->execute([$token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  return $user ?: null;
}

function auth_check_token_expired(array $user): bool {
  if (empty($user["api_token_expires"])) return false;
  return strtotime($user["api_token_expires"]) < time();
}

function auth_admin_station_gate(array $user): ?array {
  $role = (string)($user["role"] ?? "");
  if (!in_array($role, ["admin", "police_on_field"], true)) {
    return null;
  }

  if ((int)($user["is_email_verified"] ?? 0) !== 1) {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "EMAIL_NOT_VERIFIED",
        "message" => "Please verify your email to continue."
      ]
    ];
  }

  if (($user["account_status"] ?? "pending") === "disabled") {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "ACCOUNT_DISABLED",
        "message" => $role === "police_on_field" ? "Your police on field account is disabled." : "Your admin account is disabled."
      ]
    ];
  }

  if (strtolower((string)($user["account_flag_status"] ?? "none")) === "suspended") {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "ACCOUNT_SUSPENDED",
        "message" => $role === "police_on_field" ? "Your police on field account is suspended." : "Your admin account is suspended."
      ]
    ];
  }

  if (empty($user["station_id"])) {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "STATION_NOT_REGISTERED",
        "message" => "No police station is linked to this admin account."
      ]
    ];
  }

  $stationStatus = $user["station_verification_status"] ?? null;
  $stationActive = (int)($user["station_is_active"] ?? 0);

  if ($stationStatus === "approved" && $stationActive === 1 && ($user["account_status"] ?? "") === "active") {
    return null;
  }

  if ($stationStatus === "pending" || $stationStatus === "under_review") {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "STATION_PENDING",
        "message" => "Your police station account is pending verification.",
        "verification_status" => $stationStatus,
        "station_name" => $user["station_name"]
      ]
    ];
  }

  if ($stationStatus === "rejected") {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "STATION_REJECTED",
        "message" => "Your police station registration was rejected.",
        "verification_status" => $stationStatus,
        "station_name" => $user["station_name"],
        "rejected_reason" => $user["rejected_reason"] ?? null
      ]
    ];
  }

  if ($stationStatus === "resubmission_required") {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "STATION_RESUBMIT",
        "message" => "Your police station registration requires resubmission.",
        "verification_status" => $stationStatus,
        "station_name" => $user["station_name"],
        "rejected_reason" => $user["rejected_reason"] ?? null
      ]
    ];
  }

  if ($stationStatus === "draft" || $stationStatus === null) {
    return [
      "code" => 403,
      "payload" => [
        "ok" => false,
        "code" => "STATION_INCOMPLETE",
        "message" => "Your police station registration is incomplete.",
        "verification_status" => $stationStatus,
        "station_name" => $user["station_name"]
      ]
    ];
  }

  return [
    "code" => 403,
    "payload" => [
      "ok" => false,
      "code" => "STATION_NOT_APPROVED",
      "message" => "Your police station is not approved yet.",
      "verification_status" => $stationStatus,
      "station_name" => $user["station_name"]
    ]
  ];
}

function auth_station_scope(array $user): array {
  return [
    "station_id" => !empty($user["station_id"]) ? (int)$user["station_id"] : null,
    "station_name" => $user["station_name"] ?? null,
    "station_code" => $user["station_code"] ?? null,
    "station_type" => $user["station_type"] ?? null,
    "station_region" => $user["station_region"] ?? null,
    "station_province" => $user["station_province"] ?? null,
    "station_city_municipality" => $user["station_city_municipality"] ?? null,
    "station_barangay" => $user["station_barangay"] ?? null,
    "station_full_address" => $user["station_full_address"] ?? null,
    "station_lat" => isset($user["station_lat"]) ? (float)$user["station_lat"] : null,
    "station_lng" => isset($user["station_lng"]) ? (float)$user["station_lng"] : null,
    "station_verification_status" => $user["station_verification_status"] ?? null,
    "station_is_active" => isset($user["station_is_active"]) ? (int)$user["station_is_active"] : null
  ];
}