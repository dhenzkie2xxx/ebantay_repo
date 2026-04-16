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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

function get_bearer_or_query_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $token = get_bearer_or_query_token();
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

  $role = strtolower((string)($user["role"] ?? ""));
  if (!in_array($role, ["admin", "super_admin"], true)) {
    out(403, ["ok" => false, "message" => "Access denied"]);
  }

  // super admin = all citizen users
  if ($role === "super_admin") {
    $stmt = $pdo->query("
      SELECT
        u.id,
        u.firstname,
        u.lastname,
        u.email,
        u.username,
        u.account_status,
        u.is_email_verified,
        up.mobile_number,
        up.city_municipality,
        up.province,
        up.barangay,
        up.updated_at AS profile_updated_at
      FROM users u
      LEFT JOIN user_profiles up ON up.user_id = u.id
      WHERE LOWER(u.role) = 'citizen'
      ORDER BY
        CASE LOWER(COALESCE(u.account_status, 'pending'))
          WHEN 'pending' THEN 1
          WHEN 'resubmission_required' THEN 2
          WHEN 'incomplete' THEN 3
          WHEN 'rejected' THEN 4
          WHEN 'verified' THEN 5
          WHEN 'active' THEN 6
          ELSE 7
        END,
        COALESCE(up.updated_at, u.updated_at) DESC,
        u.lastname ASC,
        u.firstname ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    out(200, [
      "ok" => true,
      "scope" => [
        "role" => "super_admin",
        "province" => null,
        "city_municipality" => null
      ],
      "users" => array_map(function ($r) {
        return [
          "id" => (int)$r["id"],
          "firstname" => $r["firstname"],
          "lastname" => $r["lastname"],
          "email" => $r["email"],
          "username" => $r["username"],
          "account_status" => strtolower((string)($r["account_status"] ?: "pending")),
          "is_email_verified" => (int)($r["is_email_verified"] ?? 0),
          "mobile_number" => $r["mobile_number"],
          "city_municipality" => $r["city_municipality"],
          "province" => $r["province"],
          "barangay" => $r["barangay"],
          "profile_updated_at" => $r["profile_updated_at"],
        ];
      }, $rows)
    ]);
  }

  // station admin scope via users.station_id
  $stationStmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.station_name,
      ps.city_municipality,
      ps.province
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stationStmt->execute([(int)$user["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    out(403, [
      "ok" => false,
      "message" => "No police station is linked to this admin account"
    ]);
  }

  $scopeProvince = normalize_scope_value($station["province"] ?? null);
  $scopeCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$scopeProvince || !$scopeCity) {
    out(422, [
      "ok" => false,
      "message" => "The linked station does not have a complete province/city scope"
    ]);
  }

  $search = trim((string)($_GET["search"] ?? ""));
  $status = strtolower(trim((string)($_GET["status"] ?? "")));

  $params = [$scopeProvince, $scopeCity];

  $sql = "
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.username,
      u.account_status,
      u.is_email_verified,
      up.mobile_number,
      up.city_municipality,
      up.province,
      up.barangay,
      up.updated_at AS profile_updated_at
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE LOWER(u.role) = 'citizen'
      AND LOWER(COALESCE(up.province, '')) = LOWER(?)
      AND LOWER(COALESCE(up.city_municipality, '')) = LOWER(?)
  ";

  if ($status !== "" && in_array($status, [
    "pending",
    "active",
    "disabled",
    "incomplete",
    "verified",
    "rejected",
    "resubmission_required"
  ], true)) {
    $sql .= " AND LOWER(COALESCE(u.account_status, 'pending')) = ? ";
    $params[] = $status;
  }

  if ($search !== "") {
    $sql .= "
      AND (
        LOWER(COALESCE(u.firstname, '')) LIKE LOWER(?)
        OR LOWER(COALESCE(u.lastname, '')) LIKE LOWER(?)
        OR LOWER(COALESCE(u.email, '')) LIKE LOWER(?)
        OR LOWER(COALESCE(u.username, '')) LIKE LOWER(?)
        OR LOWER(COALESCE(up.barangay, '')) LIKE LOWER(?)
      )
    ";
    $like = "%" . $search . "%";
    array_push($params, $like, $like, $like, $like, $like);
  }

  $sql .= "
    ORDER BY
      CASE LOWER(COALESCE(u.account_status, 'pending'))
        WHEN 'pending' THEN 1
        WHEN 'resubmission_required' THEN 2
        WHEN 'incomplete' THEN 3
        WHEN 'rejected' THEN 4
        WHEN 'verified' THEN 5
        WHEN 'active' THEN 6
        ELSE 7
      END,
      COALESCE(up.updated_at, u.updated_at) DESC,
      u.lastname ASC,
      u.firstname ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  out(200, [
    "ok" => true,
    "scope" => [
      "role" => "admin",
      "station_id" => (int)$station["id"],
      "station_name" => $station["station_name"] ?? null,
      "province" => $scopeProvince,
      "city_municipality" => $scopeCity
    ],
    "users" => array_map(function ($r) {
      return [
        "id" => (int)$r["id"],
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"],
        "username" => $r["username"],
        "account_status" => strtolower((string)($r["account_status"] ?: "pending")),
        "is_email_verified" => (int)($r["is_email_verified"] ?? 0),
        "mobile_number" => $r["mobile_number"],
        "city_municipality" => $r["city_municipality"],
        "province" => $r["province"],
        "barangay" => $r["barangay"],
        "profile_updated_at" => $r["profile_updated_at"],
      ];
    }, $rows)
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}