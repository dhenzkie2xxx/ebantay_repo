<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/location_resolver.php";

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
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, ["ok" => false, "message" => "Only Station Admin can view barangay options."]);
  }

  $stationId = (int)$admin["station_id"];

  $stmt = $pdo->prepare("
    SELECT province, city_municipality, region
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    out(404, ["ok" => false, "message" => "Station not found."]);
  }

  $province = trim((string)($station["province"] ?? ""));
  $city = trim((string)($station["city_municipality"] ?? ""));
  $region = trim((string)($station["region"] ?? ""));

  $canon = canonicalize_scope($pdo, $region, $province, $city);

  if (!empty($canon["ok"])) {
    $province = $canon["province"];
    $city = $canon["city_municipality"];
  }

  /*
   * Main query: exact canonical province + city.
   */
  $barangayStmt = $pdo->prepare("
    SELECT b.canonical_name AS barangay
    FROM location_barangays b
    INNER JOIN location_cities c ON c.id = b.city_id
    INNER JOIN location_provinces p ON p.id = c.province_id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
    ORDER BY b.canonical_name ASC
  ");
  $barangayStmt->execute([$province, $city]);
  $rows = $barangayStmt->fetchAll(PDO::FETCH_ASSOC);

  /*
   * Fallback: city-only match.
   * Useful for HUCs / province naming mismatch.
   */
 if (count($rows) === 0 && $city !== "") {
  $cityCore = strtolower(trim($city));
  $cityCore = str_replace("city of ", "", $cityCore);
  $cityCore = str_replace(" city", "", $cityCore);
  $cityCore = preg_replace('/\s+/', ' ', $cityCore);

  $fallbackStmt = $pdo->prepare("
    SELECT b.canonical_name AS barangay
    FROM location_barangays b
    INNER JOIN location_cities c ON c.id = b.city_id
    WHERE
      LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
      OR LOWER(TRIM(c.canonical_name)) LIKE LOWER(TRIM(?))
      OR LOWER(
        TRIM(
          REPLACE(
            REPLACE(c.canonical_name, 'City of ', ''),
            ' City',
            ''
          )
        )
      ) = LOWER(TRIM(?))
    ORDER BY b.canonical_name ASC
  ");

  $fallbackStmt->execute([
    $city,
    "%" . $cityCore . "%",
    $cityCore
  ]);

  $rows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
}

  out(200, [
    "ok" => true,
    "province" => $province,
    "city_municipality" => $city,
    "barangays" => array_map(fn($r) => $r["barangay"], $rows)
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error.",
    "debug" => $e->getMessage()
  ]);
}