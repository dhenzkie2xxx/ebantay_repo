<?php

function admin_scope_norm(?string $value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function admin_scope_from_auth(PDO $pdo, array $authUser): array {
  $role = strtolower(trim((string)($authUser["role"] ?? "")));
  $isSuperAdmin = $role === "super_admin";

  if ($isSuperAdmin) {
    return [
      "is_global" => true,
      "station_id" => null,
      "station_region" => null,
      "station_province" => null,
      "station_city_municipality" => null,
      "station_barangay" => null,
      "station_lat" => null,
      "station_lng" => null
    ];
  }

  $stationId = isset($authUser["station_id"]) ? (int)$authUser["station_id"] : 0;
  if ($stationId <= 0) {
    return [
      "is_global" => false,
      "station_id" => null,
      "station_region" => null,
      "station_province" => null,
      "station_city_municipality" => null,
      "station_barangay" => null,
      "station_lat" => null,
      "station_lng" => null
    ];
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      region,
      province,
      city_municipality,
      barangay,
      lat,
      lng
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  return [
    "is_global" => false,
    "station_id" => $station["id"] ?? null,
    "station_region" => admin_scope_norm($station["region"] ?? null),
    "station_province" => admin_scope_norm($station["province"] ?? null),
    "station_city_municipality" => admin_scope_norm($station["city_municipality"] ?? null),
    "station_barangay" => admin_scope_norm($station["barangay"] ?? null),
    "station_lat" => isset($station["lat"]) ? (float)$station["lat"] : null,
    "station_lng" => isset($station["lng"]) ? (float)$station["lng"] : null
  ];
}

function scope_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_province"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $province = admin_scope_norm($scope["station_province"] ?? null);
  if ($province === null) {
    return " AND 1 = 0 ";
  }

  $params[$paramName] = $province;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}

function scope_city_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_city"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $city = admin_scope_norm($scope["station_city_municipality"] ?? null);
  if ($city === null) {
    return " AND 1 = 0 ";
  }

  $params[$paramName] = $city;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}

function scope_barangay_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_barangay"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $barangay = admin_scope_norm($scope["station_barangay"] ?? null);
  if ($barangay === null) {
    return "";
  }

  $params[$paramName] = $barangay;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}

function scope_region_where_clause(string $fieldName, array $scope, array &$params, string $paramName = ":scope_region"): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $region = admin_scope_norm($scope["station_region"] ?? null);
  if ($region === null) {
    return "";
  }

  $params[$paramName] = $region;
  return " AND LOWER(TRIM($fieldName)) = LOWER(TRIM($paramName)) ";
}

function scope_location_where_clause(
  array $scope,
  array &$params,
  string $provinceField,
  ?string $cityField = null,
  ?string $barangayField = null,
  ?string $regionField = null,
  string $prefix = "scope"
): string {
  if (!empty($scope["is_global"])) {
    return "";
  }

  $province = admin_scope_norm($scope["station_province"] ?? null);
  $city = admin_scope_norm($scope["station_city_municipality"] ?? null);
  $barangay = admin_scope_norm($scope["station_barangay"] ?? null);
  $region = admin_scope_norm($scope["station_region"] ?? null);

  if ($province === null) {
    return " AND 1 = 0 ";
  }

  $sql = "";

  if ($regionField && $region !== null) {
    $params[":" . $prefix . "_region"] = $region;
    $sql .= " AND LOWER(TRIM($regionField)) = LOWER(TRIM(:" . $prefix . "_region)) ";
  }

  $params[":" . $prefix . "_province"] = $province;
  $sql .= " AND LOWER(TRIM($provinceField)) = LOWER(TRIM(:" . $prefix . "_province)) ";

  if ($cityField && $city !== null) {
    $params[":" . $prefix . "_city"] = $city;
    $sql .= " AND LOWER(TRIM($cityField)) = LOWER(TRIM(:" . $prefix . "_city)) ";
  }

  if ($barangayField && $barangay !== null) {
    $params[":" . $prefix . "_barangay"] = $barangay;
    $sql .= " AND LOWER(TRIM($barangayField)) = LOWER(TRIM(:" . $prefix . "_barangay)) ";
  }

  return $sql;
}