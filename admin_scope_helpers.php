<?php

function admin_scope_from_auth(PDO $pdo, array $authUser): array {
  $role = strtolower(trim((string)($authUser["role"] ?? "")));
  $isSuperAdmin = $role === "super_admin";

  if ($isSuperAdmin) {
    return [
      "is_global" => true,
      "station_province" => null,
      "station_id" => null
    ];
  }

  $stationId = isset($authUser["station_id"]) ? (int)$authUser["station_id"] : 0;
  if ($stationId <= 0) {
    return [
      "is_global" => false,
      "station_province" => null,
      "station_id" => null
    ];
  }

  $stmt = $pdo->prepare("
    SELECT id, province
    FROM police_stations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);
  $station = $stmt->fetch(PDO::FETCH_ASSOC);

  return [
    "is_global" => false,
    "station_province" => $station["province"] ?? null,
    "station_id" => $station["id"] ?? null
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