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
    FROM police_stations ps
    WHERE ps.user_id = ?
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

function get_target_user(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.role,
      up.province,
      up.city_municipality
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
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

function normalize_requirement_code($value): string {
  $value = strtoupper(trim((string)$value));
  $value = preg_replace('/[^A-Z0-9_]+/', '_', $value);
  $value = preg_replace('/_+/', '_', $value);
  $value = trim((string)$value, '_');
  return $value;
}

function normalize_requirement_name($value): string {
  return trim((string)$value);
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

  $targetUserId = (int)($data["user_id"] ?? 0);
  if ($targetUserId <= 0) {
    out(400, ["ok" => false, "message" => "Missing or invalid user_id"]);
  }

  $targetUser = get_target_user($pdo, $targetUserId);
  if (!$targetUser) {
    out(404, ["ok" => false, "message" => "Citizen user not found"]);
  }

  if (!can_admin_access_user($scope, $targetUser)) {
    out(403, ["ok" => false, "message" => "You do not have access to this user"]);
  }

  $requirements = $data["requirements"] ?? null;
  if (!is_array($requirements)) {
    out(400, ["ok" => false, "message" => "requirements must be an array"]);
  }

  $systemCodes = ["FACE_ID", "GOV_ID", "POLICE_CLEARANCE"];

  $pdo->beginTransaction();

  // Load existing dynamic requirements under this admin scope
  if (($scope["role"] ?? "") === "super_admin") {
    $existingStmt = $pdo->prepare("
      SELECT
        id,
        requirement_code,
        requirement_name,
        is_required,
        is_system,
        station_id,
        province,
        city_municipality,
        active
      FROM user_verification_requirements
      WHERE active = 1
    ");
    $existingStmt->execute();
  } else {
    $existingStmt = $pdo->prepare("
      SELECT
        id,
        requirement_code,
        requirement_name,
        is_required,
        is_system,
        station_id,
        province,
        city_municipality,
        active
      FROM user_verification_requirements
      WHERE active = 1
        AND (
          station_id = ?
          OR (
            station_id IS NULL
            AND LOWER(COALESCE(province, '')) = LOWER(?)
            AND LOWER(COALESCE(city_municipality, '')) = LOWER(?)
          )
        )
    ");
    $existingStmt->execute([
      (int)$scope["station_id"],
      (string)$scope["province"],
      (string)$scope["city_municipality"],
    ]);
  }

  $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $existingById = [];
  foreach ($existingRows as $row) {
    $existingById[(int)$row["id"]] = $row;
  }

  $keptDynamicIds = [];
  $resultRows = [];

  foreach ($requirements as $req) {
    if (!is_array($req)) continue;

    $incomingId = isset($req["id"]) && $req["id"] !== null ? (int)$req["id"] : null;
    $incomingCode = normalize_requirement_code($req["code"] ?? "");
    $incomingName = normalize_requirement_name($req["name"] ?? "");
    $incomingRequired = !empty($req["is_required"]) ? 1 : 0;
    $incomingSystem = !empty($req["is_system"]) ? 1 : 0;

    if ($incomingName === "") {
      continue;
    }

    // Preserve baseline/system requirements only by ensuring they exist and stay active
    if ($incomingSystem || in_array($incomingCode, $systemCodes, true)) {
      if ($incomingCode === "") {
        $incomingCode = normalize_requirement_code($incomingName);
      }

      $findSystemStmt = $pdo->prepare("
        SELECT id
        FROM user_verification_requirements
        WHERE requirement_code = ?
        LIMIT 1
      ");
      $findSystemStmt->execute([$incomingCode]);
      $systemExistingId = $findSystemStmt->fetchColumn();

      if ($systemExistingId) {
        $updateSystemStmt = $pdo->prepare("
          UPDATE user_verification_requirements
          SET
            requirement_name = ?,
            is_required = ?,
            is_system = 1,
            active = 1,
            updated_at = CURRENT_TIMESTAMP
          WHERE id = ?
        ");
        $updateSystemStmt->execute([
          $incomingName,
          $incomingRequired,
          (int)$systemExistingId,
        ]);

        $resultRows[] = [
          "id" => (int)$systemExistingId,
          "code" => $incomingCode,
          "name" => $incomingName,
          "is_required" => $incomingRequired === 1,
          "is_system" => true,
        ];
      } else {
        $insertSystemStmt = $pdo->prepare("
          INSERT INTO user_verification_requirements
          (
            requirement_code,
            requirement_name,
            is_required,
            is_system,
            station_id,
            city_municipality,
            province,
            active,
            created_by
          )
          VALUES (?, ?, ?, 1, NULL, NULL, NULL, 1, ?)
        ");
        $insertSystemStmt->execute([
          $incomingCode,
          $incomingName,
          $incomingRequired,
          (int)$adminUser["id"],
        ]);

        $resultRows[] = [
          "id" => (int)$pdo->lastInsertId(),
          "code" => $incomingCode,
          "name" => $incomingName,
          "is_required" => $incomingRequired === 1,
          "is_system" => true,
        ];
      }

      continue;
    }

    // Dynamic requirement
    if ($incomingCode === "") {
      $incomingCode = normalize_requirement_code($incomingName);
    }

    if ($incomingCode === "") {
      continue;
    }

    if ($incomingId && isset($existingById[$incomingId])) {
      $existing = $existingById[$incomingId];

      if ((int)$existing["is_system"] === 1) {
        continue;
      }

      $updateStmt = $pdo->prepare("
        UPDATE user_verification_requirements
        SET
          requirement_code = ?,
          requirement_name = ?,
          is_required = ?,
          active = 1,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
      ");
      $updateStmt->execute([
        $incomingCode,
        $incomingName,
        $incomingRequired,
        $incomingId,
      ]);

      $keptDynamicIds[] = $incomingId;

      $resultRows[] = [
        "id" => $incomingId,
        "code" => $incomingCode,
        "name" => $incomingName,
        "is_required" => $incomingRequired === 1,
        "is_system" => false,
      ];

      continue;
    }

    // Try to match existing dynamic requirement by scoped code before insert
    if (($scope["role"] ?? "") === "super_admin") {
      $findStmt = $pdo->prepare("
        SELECT id
        FROM user_verification_requirements
        WHERE requirement_code = ?
          AND is_system = 0
        LIMIT 1
      ");
      $findStmt->execute([$incomingCode]);
    } else {
      $findStmt = $pdo->prepare("
        SELECT id
        FROM user_verification_requirements
        WHERE requirement_code = ?
          AND is_system = 0
          AND (
            station_id = ?
            OR (
              station_id IS NULL
              AND LOWER(COALESCE(province, '')) = LOWER(?)
              AND LOWER(COALESCE(city_municipality, '')) = LOWER(?)
            )
          )
        LIMIT 1
      ");
      $findStmt->execute([
        $incomingCode,
        (int)$scope["station_id"],
        (string)$scope["province"],
        (string)$scope["city_municipality"],
      ]);
    }

    $matchedId = $findStmt->fetchColumn();

    if ($matchedId) {
      $matchedId = (int)$matchedId;

      $updateStmt = $pdo->prepare("
        UPDATE user_verification_requirements
        SET
          requirement_name = ?,
          is_required = ?,
          active = 1,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
      ");
      $updateStmt->execute([
        $incomingName,
        $incomingRequired,
        $matchedId,
      ]);

      $keptDynamicIds[] = $matchedId;

      $resultRows[] = [
        "id" => $matchedId,
        "code" => $incomingCode,
        "name" => $incomingName,
        "is_required" => $incomingRequired === 1,
        "is_system" => false,
      ];
    } else {
      $insertStmt = $pdo->prepare("
        INSERT INTO user_verification_requirements
        (
          requirement_code,
          requirement_name,
          is_required,
          is_system,
          station_id,
          city_municipality,
          province,
          active,
          created_by
        )
        VALUES (?, ?, ?, 0, ?, ?, ?, 1, ?)
      ");

      $stationId = ($scope["role"] ?? "") === "super_admin" ? null : (int)$scope["station_id"];
      $city = ($scope["role"] ?? "") === "super_admin" ? null : (string)$scope["city_municipality"];
      $province = ($scope["role"] ?? "") === "super_admin" ? null : (string)$scope["province"];

      $insertStmt->execute([
        $incomingCode,
        $incomingName,
        $incomingRequired,
        $stationId,
        $city,
        $province,
        (int)$adminUser["id"],
      ]);

      $newId = (int)$pdo->lastInsertId();
      $keptDynamicIds[] = $newId;

      $resultRows[] = [
        "id" => $newId,
        "code" => $incomingCode,
        "name" => $incomingName,
        "is_required" => $incomingRequired === 1,
        "is_system" => false,
      ];
    }
  }

  // Deactivate removed dynamic requirements in current scope
  foreach ($existingRows as $existing) {
    $existingId = (int)$existing["id"];
    $isSystem = (int)($existing["is_system"] ?? 0) === 1;

    if ($isSystem) {
      continue;
    }

    if (!in_array($existingId, $keptDynamicIds, true)) {
      $deactivateStmt = $pdo->prepare("
        UPDATE user_verification_requirements
        SET
          active = 0,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
      ");
      $deactivateStmt->execute([$existingId]);
    }
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "User verification requirements saved successfully",
    "scope" => [
      "role" => $scope["role"],
      "station_id" => $scope["station_id"],
      "station_name" => $scope["station_name"],
      "province" => $scope["province"],
      "city_municipality" => $scope["city_municipality"],
    ],
    "user" => [
      "id" => (int)$targetUser["id"],
      "firstname" => $targetUser["firstname"],
      "lastname" => $targetUser["lastname"],
      "email" => $targetUser["email"],
    ],
    "requirements" => $resultRows,
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