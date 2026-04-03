<?php

function station_assignment_normalize(?string $value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function station_assignment_haversine_meters(
  float $lat1,
  float $lng1,
  float $lat2,
  float $lng2
): float {
  $earth = 6371000.0;

  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a = sin($dLat / 2) * sin($dLat / 2)
     + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
     * sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

function station_assignment_pick_nearest(PDO $pdo, string $sql, array $params, float $lat, float $lng): ?array {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$stations) return null;

  $nearest = null;
  $nearestDistance = null;

  foreach ($stations as $station) {
    if (!isset($station["lat"], $station["lng"])) {
      continue;
    }

    if ($station["lat"] === null || $station["lng"] === null) {
      continue;
    }

    $d = station_assignment_haversine_meters(
      $lat,
      $lng,
      (float)$station["lat"],
      (float)$station["lng"]
    );

    if ($nearestDistance === null || $d < $nearestDistance) {
      $nearestDistance = $d;
      $nearest = $station;
    }
  }

  if (!$nearest) return null;

  $nearest["distance_m"] = (int)round($nearestDistance);
  return $nearest;
}

function find_nearest_station_in_province(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  $province = station_assignment_normalize($province);
  if ($province === null) return null;

  $sql = "
    SELECT
      id,
      station_name,
      station_code,
      station_type,
      region,
      province,
      city_municipality,
      barangay,
      sitio,
      street_address,
      full_address,
      contact_person,
      contact_position,
      contact_mobile,
      contact_landline,
      contact_email,
      emergency_contact,
      operating_hours,
      lat,
      lng
    FROM police_stations
    WHERE verification_status = 'approved'
      AND is_active = 1
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
  ";

  return station_assignment_pick_nearest($pdo, $sql, [$province], $lat, $lng);
}

function find_nearest_station_in_city(PDO $pdo, float $lat, float $lng, ?string $province, ?string $cityMunicipality): ?array {
  $province = station_assignment_normalize($province);
  $cityMunicipality = station_assignment_normalize($cityMunicipality);

  if ($province === null || $cityMunicipality === null) return null;

  $sql = "
    SELECT
      id,
      station_name,
      station_code,
      station_type,
      region,
      province,
      city_municipality,
      barangay,
      sitio,
      street_address,
      full_address,
      contact_person,
      contact_position,
      contact_mobile,
      contact_landline,
      contact_email,
      emergency_contact,
      operating_hours,
      lat,
      lng
    FROM police_stations
    WHERE verification_status = 'approved'
      AND is_active = 1
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
  ";

  return station_assignment_pick_nearest($pdo, $sql, [$province, $cityMunicipality], $lat, $lng);
}

function assign_incident_station(PDO $pdo, float $lat, float $lng, ?string $province, ?string $cityMunicipality): ?array {
  $cityMatch = find_nearest_station_in_city($pdo, $lat, $lng, $province, $cityMunicipality);
  if ($cityMatch) {
    $cityMatch["_assignment_rule"] = "CITY_FIRST";
    return $cityMatch;
  }

  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);
  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_FALLBACK";
    return $provinceMatch;
  }

  return null;
}

function assign_panic_station(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);
  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_NEAREST";
    return $provinceMatch;
  }

  return null;
}