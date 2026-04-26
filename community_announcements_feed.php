<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
if ($token === "") {
  $token = trim($_GET["token"] ?? "");
}

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

try {
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  if ($user["role"] !== "citizen") {
    out(403, [
      "ok" => false,
      "message" => "Citizen access only."
    ]);
  }

  $profileStmt = $pdo->prepare("
    SELECT
      region,
      province,
      city_municipality
    FROM user_profiles
    WHERE user_id = ?
    LIMIT 1
  ");
  $profileStmt->execute([(int)$user["id"]]);
  $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

  $region = trim((string)($profile["region"] ?? ""));
  $province = trim((string)($profile["province"] ?? ""));
  $cityMunicipality = trim((string)($profile["city_municipality"] ?? ""));

  if ($province === "" || $cityMunicipality === "") {
    out(200, [
      "ok" => true,
      "scope" => [
        "region" => $region ?: null,
        "province" => $province ?: null,
        "city_municipality" => $cityMunicipality ?: null
      ],
      "announcements" => []
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      station_id,
      created_by,
      title,
      message,
      region,
      province,
      city_municipality,
      status,
      priority,
      created_at,
      updated_at
    FROM community_announcements
    WHERE status = 'active'
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ORDER BY
      FIELD(priority, 'urgent', 'important', 'normal'),
      created_at DESC
    LIMIT 100
  ");

  $stmt->execute([
    $province,
    $cityMunicipality
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $announcements = array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "station_id" => (int)$r["station_id"],
      "title" => $r["title"],
      "message" => $r["message"],
      "region" => $r["region"],
      "province" => $r["province"],
      "city_municipality" => $r["city_municipality"],
      "priority" => $r["priority"],
      "created_at" => $r["created_at"],
      "updated_at" => $r["updated_at"]
    ];
  }, $rows);

  out(200, [
    "ok" => true,
    "scope" => [
      "region" => $region ?: null,
      "province" => $province ?: null,
      "city_municipality" => $cityMunicipality ?: null
    ],
    "announcements" => $announcements
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}