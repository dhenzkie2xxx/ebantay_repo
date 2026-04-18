<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$allowedOrigins = [
  "http://localhost:5173",
  "http://127.0.0.1:5173",
  "https://ebantay.top.gen.in",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit;
}

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

function get_request_json(): array {
  $raw = file_get_contents("php://input");
  if ($raw === "") return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

function get_admin_scope(PDO $pdo, array $adminUser): array {
  $role = strtolower((string)($adminUser["role"] ?? ""));

  if ($role === "super_admin") {
    return [
      "ok" => true,
      "role" => "super_admin",
      "station_id" => null,
      "province" => null,
      "city_municipality" => null,
      "station_name" => null,
    ];
  }

  if ($role !== "admin") {
    return ["ok" => false, "message" => "Access denied"];
  }

  $stmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.station_name,
      ps.province,
      ps.city_municipality
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$adminUser["id"]]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    return ["ok" => false, "message" => "No police station is linked to this admin account"];
  }

  $province = normalize_scope_value($station["province"] ?? null);
  $city = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$province || !$city) {
    return ["ok" => false, "message" => "The linked station does not have a complete province/city scope"];
  }

  return [
    "ok" => true,
    "role" => "admin",
    "station_id" => (int)$station["id"],
    "station_name" => $station["station_name"] ?? null,
    "province" => $province,
    "city_municipality" => $city,
  ];
}

function can_admin_access_user(array $scope, array $targetUser): bool {
  if (($scope["role"] ?? "") === "super_admin") {
    return true;
  }

  $targetProvince = normalize_scope_value($targetUser["province"] ?? null);
  $targetCity = normalize_scope_value($targetUser["city_municipality"] ?? null);

  if (!$targetProvince || !$targetCity) {
    return false;
  }

  return
    strcasecmp((string)$scope["province"], (string)$targetProvince) === 0 &&
    strcasecmp((string)$scope["city_municipality"], (string)$targetCity) === 0;
}

function queue_user_notification(
  PDO $pdo,
  int $userId,
  string $type,
  string $title,
  string $message,
  string $severity = "MEDIUM"
): void {
  $severity = strtoupper(trim($severity));
  if (!in_array($severity, ["LOW", "MEDIUM", "HIGH"], true)) {
    $severity = "MEDIUM";
  }

  $stmt = $pdo->prepare("
    INSERT INTO notification_alerts
    (
      user_id,
      type,
      title,
      message,
      severity,
      is_read,
      created_at
    )
    VALUES (?, ?, ?, ?, ?, 0, NOW())
  ");
  $stmt->execute([
    $userId,
    $type,
    $title,
    $message,
    $severity,
  ]);
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_body_token();
  if ($token === "") {
    out(401, ["ok" => false, "message" => "Missing token"]);
  }

  $adminUser = auth_get_user_by_token($pdo, $token);
  if (!$adminUser) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($adminUser)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $scope = get_admin_scope($pdo, $adminUser);
  if (!($scope["ok"] ?? false)) {
    out(403, ["ok" => false, "message" => $scope["message"] ?? "Access denied"]);
  }

  $data = get_request_json();

  $submissionId = (int)($data["submission_id"] ?? 0);
  $status = strtolower(trim((string)($data["status"] ?? "")));
  $remarks = trim((string)($data["remarks"] ?? ""));

  if ($submissionId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid submission_id"]);
  }

  if (!in_array($status, ["approved", "rejected"], true)) {
    out(400, ["ok" => false, "message" => "Invalid status"]);
  }

  if ($status === "rejected" && $remarks === "") {
    out(400, ["ok" => false, "message" => "Remarks are required when rejecting a document"]);
  }

  $docStmt = $pdo->prepare("
    SELECT
      s.id,
      s.user_id,
      s.requirement_id,
      s.status AS submission_status,
      s.file_name,
      s.mime_type,
      s.remarks AS submission_remarks,
      r.requirement_name,
      r.requirement_code,
      u.firstname,
      u.lastname,
      u.email,
      up.province,
      up.city_municipality
    FROM user_requirement_submissions s
    INNER JOIN user_verification_requirements r ON r.id = s.requirement_id
    INNER JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE s.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $docStmt->execute([$submissionId]);
  $doc = $docStmt->fetch(PDO::FETCH_ASSOC);

  if (!$doc) {
    out(404, ["ok" => false, "message" => "Submission not found"]);
  }

  if (!can_admin_access_user($scope, $doc)) {
    out(403, ["ok" => false, "message" => "You do not have access to this user"]);
  }

  $pdo->beginTransaction();

  $updateStmt = $pdo->prepare("
    UPDATE user_requirement_submissions
    SET
      status = ?,
      remarks = ?,
      reviewed_at = NOW(),
      reviewed_by = ?
    WHERE id = ?
    LIMIT 1
  ");
  $updateStmt->execute([
    $status,
    $remarks !== "" ? $remarks : ($status === "approved" ? "Approved by station admin" : null),
    (int)$adminUser["id"],
    $submissionId,
  ]);

  if ($status === "rejected") {
    $userReason = "Please re-upload " . ($doc["requirement_name"] ?: "the required document") . ".";
    if ($remarks !== "") {
      $userReason .= " Reason: " . $remarks;
    }

    $userStmt = $pdo->prepare("
      UPDATE users
      SET
        valid = 'unvalid',
        account_status = 'resubmission_required',
        rejected_reason = ?,
        approved_by = NULL,
        approved_at = NULL,
        updated_at = NOW()
      WHERE id = ?
      LIMIT 1
    ");
    $userStmt->execute([
      $userReason,
      (int)$doc["user_id"],
    ]);

    $latestReqStmt = $pdo->prepare("
      SELECT id
      FROM user_verification_requests
      WHERE user_id = ?
      ORDER BY id DESC
      LIMIT 1
    ");
    $latestReqStmt->execute([(int)$doc["user_id"]]);
    $latestReqId = $latestReqStmt->fetchColumn();

    if ($latestReqId) {
      $vrStmt = $pdo->prepare("
        UPDATE user_verification_requests
        SET
          status = 'resubmission_required',
          reviewed_at = NOW(),
          reviewed_by = ?,
          remarks = ?
        WHERE id = ?
      ");
      $vrStmt->execute([
        (int)$adminUser["id"],
        $userReason,
        (int)$latestReqId,
      ]);
    }

    queue_user_notification(
      $pdo,
      (int)$doc["user_id"],
      "ACCOUNT_STATUS",
      "Document Requires Resubmission",
      $userReason,
      "HIGH"
    );
  }

  if ($status === "approved") {
    queue_user_notification(
      $pdo,
      (int)$doc["user_id"],
      "ACCOUNT_STATUS",
      "Document Approved",
      ($doc["requirement_name"] ?: "A document") . " has been approved by the station admin.",
      "LOW"
    );
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => $status === "approved"
      ? "Document approved successfully"
      : "Document rejected successfully",
    "submission" => [
      "id" => (int)$doc["id"],
      "user_id" => (int)$doc["user_id"],
      "requirement_id" => (int)$doc["requirement_id"],
      "requirement_name" => $doc["requirement_name"],
      "status" => $status,
      "remarks" => $remarks,
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage(),
  ]);
}