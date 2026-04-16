<?php

function haversine_distance($lat1, $lon1, $lat2, $lon2) {
  $R = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);

  $a = sin($dLat / 2) * sin($dLat / 2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLon / 2) * sin($dLon / 2);

  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $R * $c;
}

function find_nearest_station_in_province(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  if (!$province) return null;

  $stmt = $pdo->prepare("
    SELECT *
    FROM police_stations
    WHERE verification_status = 'approved'
      AND is_active = 1
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
  ");
  $stmt->execute([$province]);

  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDist = null;

  foreach ($stations as $s) {
    if (!isset($s["lat"], $s["lng"])) continue;
    if (!is_numeric($s["lat"]) || !is_numeric($s["lng"])) continue;

    $d = haversine_distance($lat, $lng, (float)$s["lat"], (float)$s["lng"]);

    if ($nearestDist === null || $d < $nearestDist) {
      $nearestDist = $d;
      $nearest = $s;
      $nearest["distance_m"] = (int) round($d);
    }
  }

  return $nearest;
}

function find_nearest_station_in_city(PDO $pdo, float $lat, float $lng, ?string $province, ?string $city): ?array {
  if (!$province || !$city) return null;

  $stmt = $pdo->prepare("
    SELECT *
    FROM police_stations
    WHERE verification_status = 'approved'
      AND is_active = 1
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
  ");
  $stmt->execute([$province, $city]);

  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDist = null;

  foreach ($stations as $s) {
    if (!isset($s["lat"], $s["lng"])) continue;
    if (!is_numeric($s["lat"]) || !is_numeric($s["lng"])) continue;

    $d = haversine_distance($lat, $lng, (float)$s["lat"], (float)$s["lng"]);

    if ($nearestDist === null || $d < $nearestDist) {
      $nearestDist = $d;
      $nearest = $s;
      $nearest["distance_m"] = (int) round($d);
    }
  }

  return $nearest;
}

function assign_panic_station(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);

  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_NEAREST";
    return $provinceMatch;
  }

  return null;
}

function assign_incident_station(PDO $pdo, float $lat, float $lng, ?string $province, ?string $city): ?array {
  $cityMatch = find_nearest_station_in_city($pdo, $lat, $lng, $province, $city);
  if ($cityMatch) {
    $cityMatch["_assignment_rule"] = "CITY_STRICT";
    return $cityMatch;
  }

  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);
  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_FALLBACK";
    return $provinceMatch;
  }

  return null;
}

function find_nearest_police_on_field(PDO $pdo, float $lat, float $lng, ?string $province = null): ?array {
  $sql = "
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.email,
      u.station_id,
      ul.lat,
      ul.lng,
      ul.accuracy_m,
      ul.created_at,
      ps.station_name,
      ps.station_code,
      ps.region,
      ps.province,
      ps.city_municipality,
      ps.barangay,
      ps.full_address
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    INNER JOIN (
      SELECT ul1.*
      FROM user_locations ul1
      INNER JOIN (
        SELECT user_id, MAX(created_at) AS max_created_at
        FROM user_locations
        GROUP BY user_id
      ) latest ON latest.user_id = ul1.user_id AND latest.max_created_at = ul1.created_at
    ) ul ON ul.user_id = u.id
    WHERE u.role = 'police_on_field'
      AND u.valid = 'valid'
      AND u.account_status = 'active'
      AND u.is_email_verified = 1
      AND ps.verification_status = 'approved'
      AND ps.is_active = 1
  ";

  $params = [];
  if ($province) {
    $sql .= " AND LOWER(TRIM(ps.province)) = LOWER(TRIM(?)) ";
    $params[] = $province;
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDist = null;
  foreach ($rows as $r) {
    if (!isset($r['lat'], $r['lng']) || !is_numeric($r['lat']) || !is_numeric($r['lng'])) continue;
    $d = haversine_distance($lat, $lng, (float)$r['lat'], (float)$r['lng']);
    if ($nearestDist === null || $d < $nearestDist) {
      $nearestDist = $d;
      $nearest = $r;
      $nearest['distance_m'] = (int) round($d);
    }
  }
  return $nearest;
}
