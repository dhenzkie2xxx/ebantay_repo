<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $scope = admin_scope_from_auth($pdo, $AUTH_USER);

  $selectedProvince = trim((string)($_GET["province"] ?? ""));
  $selectedCity = trim((string)($_GET["city_municipality"] ?? ""));

  $provinceLimit = (int)($_GET["province_limit"] ?? 10);
  $cityLimit = (int)($_GET["city_limit"] ?? 10);
  $barangayLimit = (int)($_GET["barangay_limit"] ?? 10);

  if ($provinceLimit < 1) $provinceLimit = 10;
  if ($provinceLimit > 20) $provinceLimit = 20;

  if ($cityLimit < 1) $cityLimit = 10;
  if ($cityLimit > 20) $cityLimit = 20;

  if ($barangayLimit < 1) $barangayLimit = 10;
  if ($barangayLimit > 20) $barangayLimit = 20;

  $roleMode = !empty($scope["is_global"]) ? "super_admin" : "station_admin";

  $provinces = [];
  $cities = [];
  $barangays = [];

  // -----------------------------
  // SUPER ADMIN
  // -----------------------------
  if (!empty($scope["is_global"])) {
    $provinceStmt = $pdo->prepare("
      SELECT
        province,
        COUNT(*) AS count
      FROM incident_reports
      WHERE verification_status = 'VERIFIED'
        AND province IS NOT NULL
        AND TRIM(province) <> ''
      GROUP BY province
      ORDER BY count DESC, province ASC
      LIMIT $provinceLimit
    ");
    $provinceStmt->execute();
    $provinces = $provinceStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedProvince !== "") {
      $cityStmt = $pdo->prepare("
        SELECT
          city_municipality,
          COUNT(*) AS count
        FROM incident_reports
        WHERE verification_status = 'VERIFIED'
          AND LOWER(TRIM(province)) = LOWER(TRIM(:province))
          AND city_municipality IS NOT NULL
          AND TRIM(city_municipality) <> ''
        GROUP BY city_municipality
        ORDER BY count DESC, city_municipality ASC
        LIMIT $cityLimit
      ");
      $cityStmt->execute([
        ":province" => $selectedProvince
      ]);
      $cities = $cityStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($selectedProvince !== "" && $selectedCity !== "") {
      $barangayStmt = $pdo->prepare("
        SELECT
          barangay,
          COUNT(*) AS count
        FROM incident_reports
        WHERE verification_status = 'VERIFIED'
          AND LOWER(TRIM(province)) = LOWER(TRIM(:province))
          AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(:city))
          AND barangay IS NOT NULL
          AND TRIM(barangay) <> ''
        GROUP BY barangay
        ORDER BY count DESC, barangay ASC
        LIMIT $barangayLimit
      ");
      $barangayStmt->execute([
        ":province" => $selectedProvince,
        ":city" => $selectedCity
      ]);
      $barangays = $barangayStmt->fetchAll(PDO::FETCH_ASSOC);
    }
  } else {
    // -----------------------------
    // STATION ADMIN
    // -----------------------------
    $stationProvince = trim((string)($scope["station_province"] ?? ""));
    $stationCity = trim((string)($scope["station_city_municipality"] ?? ""));

    $cityStmt = $pdo->prepare("
      SELECT
        city_municipality,
        COUNT(*) AS count
      FROM incident_reports
      WHERE verification_status = 'VERIFIED'
        AND LOWER(TRIM(province)) = LOWER(TRIM(:province))
        AND city_municipality IS NOT NULL
        AND TRIM(city_municipality) <> ''
      GROUP BY city_municipality
      ORDER BY count DESC, city_municipality ASC
      LIMIT $cityLimit
    ");
    $cityStmt->execute([
      ":province" => $stationProvince
    ]);
    $cities = $cityStmt->fetchAll(PDO::FETCH_ASSOC);

    $barangayStmt = $pdo->prepare("
      SELECT
        barangay,
        COUNT(*) AS count
      FROM incident_reports
      WHERE verification_status = 'VERIFIED'
        AND LOWER(TRIM(province)) = LOWER(TRIM(:province))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(:city))
        AND barangay IS NOT NULL
        AND TRIM(barangay) <> ''
      GROUP BY barangay
      ORDER BY count DESC, barangay ASC
      LIMIT $barangayLimit
    ");
    $barangayStmt->execute([
      ":province" => $stationProvince,
      ":city" => $stationCity
    ]);
    $barangays = $barangayStmt->fetchAll(PDO::FETCH_ASSOC);
  }

  echo json_encode([
    "ok" => true,
    "scope" => $scope,
    "mode" => $roleMode,
    "selected" => [
      "province" => $selectedProvince !== "" ? $selectedProvince : null,
      "city_municipality" => $selectedCity !== "" ? $selectedCity : null
    ],
    "provinces" => array_map(function ($r) {
      return [
        "name" => $r["province"],
        "count" => (int)$r["count"]
      ];
    }, $provinces),
    "cities" => array_map(function ($r) {
      return [
        "name" => $r["city_municipality"],
        "count" => (int)$r["count"]
      ];
    }, $cities),
    "barangays" => array_map(function ($r) {
      return [
        "name" => $r["barangay"],
        "count" => (int)$r["count"]
      ];
    }, $barangays)
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => $e->getMessage(),
    "file" => basename(__FILE__),
    "line" => $e->getLine()
  ]);
}