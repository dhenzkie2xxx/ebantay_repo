<?php
require_once __DIR__ . "/db.php";

function auth_json_input(): array {
  $raw = file_get_contents("php://input");
  if ($raw === false || $raw === "") return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function auth_out(int $status, array $payload): void {
  http_response_code($status);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($payload);
  exit;
}

function bearer_token(): string {
  $header = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["Authorization"] ?? "";
  if (!$header && function_exists("apache_request_headers")) {
    $headers = apache_request_headers();
    if (is_array($headers)) {
      $header = $headers["Authorization"] ?? $headers["authorization"] ?? "";
    }
  }

  if (preg_match('/Bearer\s+(.+)$/i', trim($header), $m)) {
    return trim($m[1]);
  }
  return "";
}

function auth_station_scope(array $user): array {
  return [
    "station_id" => isset($user["station_id"]) ? (int)$user["station_id"] : null,
    "station_name" => $user["station_name"] ?? null,
    "station_verification_status" => $user["station_verification_status"] ?? null,
    "station_city_municipality" => $user["station_city_municipality"] ?? null,
    "station_province" => $user["station_province"] ?? null,
    "station_region" => $user["station_region"] ?? null,
  ];
}

function auth_get_user_by_token(PDO $pdo, ?string $token = null): ?array {
  $token = trim((string)($token ?? ""));
  if ($token === "") {
    $token = bearer_token();
  }
  if ($token === "") {
    return null;
  }

  $sql = "
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.role,
      u.valid,
      u.account_status,
      u.approved_by,
      u.approved_at,
      u.rejected_reason,
      u.is_email_verified,
      u.email_verify_token,
      u.email_verify_expires,
      u.api_token,
      u.api_token_expires,

      -- account safety / flagging
      u.false_report_count,
      u.false_alarm_count,
      u.account_flag_status,
      u.flagged_at,
      u.flagged_reason,
      u.suspended_at,
      u.suspended_by,
      u.suspension_reason,

      -- station scope
      u.station_id,
      ps.station_name,
      ps.verification_status AS station_verification_status,
      ps.city_municipality AS station_city_municipality,
      ps.province AS station_province,
      ps.region AS station_region

    FROM users u
    LEFT JOIN police_stations ps
      ON ps.id = u.station_id
    WHERE u.api_token = ?
    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$token]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  return $user ?: null;
}

function auth_check_token_expired(array $user): bool {
  $expires = $user["api_token_expires"] ?? null;
  if (!$expires) return false;

  $expTs = strtotime((string)$expires);
  if ($expTs === false) return false;

  return $expTs < time();
}

function auth_require_login(PDO $pdo): array {
  $user = auth_get_user_by_token($pdo);
  if (!$user) {
    auth_out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    auth_out(401, ["ok" => false, "message" => "Token expired"]);
  }

  return $user;
}

function auth_require_roles(PDO $pdo, array $allowedRoles): array {
  $user = auth_require_login($pdo);
  $role = strtolower((string)($user["role"] ?? ""));

  $normalizedAllowed = array_map(
    fn($r) => strtolower(trim((string)$r)),
    $allowedRoles
  );

  if (!in_array($role, $normalizedAllowed, true)) {
    auth_out(403, ["ok" => false, "message" => "Access denied"]);
  }

  return $user;
}