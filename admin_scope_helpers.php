<?php

function admin_scope_from_auth(PDO $pdo, array $authUser): array {
  $role = strtolower(trim((string)($authUser["role"] ?? "")));
  $isSuperAdmin = $role === "super_admin";

  if ($isSuperAdmin) {
    return [
      "is_global" => true,
      "station_id" => null,
      "station_province" => null,
      "station_city_municipality" => null,
      "station_barangay" => null
    ];
  }

  $stationId = isset($authUser["station_id"]) ? (int)$authUser["station_id"] : 0;
  if ($stationId <= 0) {
    return [
      "is_global" => false,
      "station_id" => null,
      "station_province" => null,
      "station_city_municipality" => null,
      "station_barangay" => null
    ];
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      province,
      city_municipality,
      barangay
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  return [
    "is_global" => false,
    "station_id" => $station["id"] ?? null,
    "station_province" => $station["province"] ?? null,
    "station_city_municipality" => $station["city_municipality"] ?? null,
    "station_barangay" => $station["barangay"] ?? null
  ];
}

function scope_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_province"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $province = trim((string)($scope["station_province"] ?? ""));
  if ($province === "") {
    return " AND 1 = 0 ";
  }

  $params[$paramName] = $province;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}

function scope_city_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_city"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $city = trim((string)($scope["station_city_municipality"] ?? ""));
  if ($city === "") {
    return " AND 1 = 0 ";
  }

  $params[$paramName] = $city;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}