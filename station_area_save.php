<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/audit_log_helper.php";
require_once __DIR__ . "/station_area_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function clean_text($value): ?string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value === "" ? null : $value;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$token = bearer_token();

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, [
    "ok" => false,
    "message" => "Invalid JSON body"
  ]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

if ($token === "") {
  out(401, [
    "ok" => false,
    "message" => "Missing token"
  ]);
}

$barangays = $data["barangays"] ?? [];

if (!is_array($barangays)) {
  out(400, [
    "ok" => false,
    "message" => "Barangays must be an array"
  ]);
}

try {

  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, [
      "ok" => false,
      "message" => "Unauthorized"
    ]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, [
      "ok" => false,
      "message" => "Token expired"
    ]);
  }

  $gate = auth_admin_station_gate($admin);

  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can manage Area of Responsibility."
    ]);
  }

  $stationId = (int)$admin["station_id"];

  if ($stationId <= 0) {
    out(403, [
      "ok" => false,
      "message" => "Station Admin has no assigned station."
    ]);
  }

  $stationStmt = $pdo->prepare("
    SELECT
      id,
      station_name,
      province,
      city_municipality
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");

  $stationStmt->execute([$stationId]);

  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) {
    out(404, [
      "ok" => false,
      "message" => "Station not found."
    ]);
  }

  $province = clean_text($station["province"]);
  $city = clean_text($station["city_municipality"]);

  if (!$province || !$city) {
    out(400, [
      "ok" => false,
      "message" => "Station province/city is incomplete."
    ]);
  }

  $normalizedBarangays = [];

  foreach ($barangays as $b) {

    $b = clean_text($b);

    if (!$b) continue;

    $normalizedBarangays[] = $b;
  }

  $normalizedBarangays = array_values(array_unique($normalizedBarangays));

  $pdo->beginTransaction();

  /*
   * Validate conflicts:
   * Barangay cannot belong to another station admin.
   */
  if (count($normalizedBarangays) > 0) {

    $placeholders = implode(",", array_fill(0, count($normalizedBarangays), "?"));

    $params = array_merge(
      [$province, $city, $stationId],
      $normalizedBarangays
    );

    $conflictStmt = $pdo->prepare("
      SELECT
        sab.barangay,
        ps.station_name
      FROM station_area_barangays sab
      INNER JOIN police_stations ps
        ON ps.id = sab.station_id
      WHERE LOWER(TRIM(sab.province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(sab.city_municipality)) = LOWER(TRIM(?))
        AND sab.station_id <> ?
        AND LOWER(TRIM(sab.barangay)) IN ($placeholders)
      LIMIT 1
    ");

    $conflictStmt->execute($params);

    $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);

    if ($conflict) {

      $pdo->rollBack();

      out(409, [
        "ok" => false,
        "message" => "Barangay '{$conflict["barangay"]}' is already assigned to station '{$conflict["station_name"]}'."
      ]);
    }
  }

  /*
   * Replace station assignments
   */
  $deleteStmt = $pdo->prepare("
    DELETE FROM station_area_barangays
    WHERE station_id = ?
  ");

  $deleteStmt->execute([$stationId]);

  if (count($normalizedBarangays) > 0) {

    $insertStmt = $pdo->prepare("
      INSERT INTO station_area_barangays (
        station_id,
        province,
        city_municipality,
        barangay
      )
      VALUES (?, ?, ?, ?)
    ");

    foreach ($normalizedBarangays as $barangay) {

      $insertStmt->execute([
        $stationId,
        $province,
        $city,
        $barangay
      ]);
    }
  }

  write_audit_log(
    $pdo,
    $admin,
    "STATION_AREA_UPDATED",
    "station_area_barangays",
    $stationId,
    count($normalizedBarangays) > 0
      ? "Station Admin updated barangay Area of Responsibility."
      : "Station Admin reset Area of Responsibility to whole city coverage.",
    [
      "module" => "station_area",
      "barangay_count" => count($normalizedBarangays),
      "province" => $province,
      "city_municipality" => $city
    ]
  );

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => count($normalizedBarangays) > 0
      ? "Area of Responsibility updated successfully."
      : "Area cleared. Station now covers the whole city/municipality.",
    "mode" => count($normalizedBarangays) > 0
      ? "barangay_specific"
      : "whole_city",
    "barangays" => $normalizedBarangays
  ]);

} catch (Throwable $e) {

  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error.",
    "debug" => $e->getMessage()
  ]);
}