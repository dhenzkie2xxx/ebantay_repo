<?php

/**
 * Haversine distance in meters
 */
function haversine_distance($lat1, $lon1, $lat2, $lon2) {
  $R = 6371000; // meters
  $dLat = deg2rad($lat2 - $lat1);
  $dLon = deg2rad($lon2 - $lon1);

  $a = sin($dLat / 2) * sin($dLat / 2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLon / 2) * sin($dLon / 2);

  $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
  return $R * $c;
}

/**
 * Get nearest station within a specific province
 */
function find_nearest_station_in_province(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  if (!$province) return null;

  $stmt = $pdo->prepare("
    SELECT *
    FROM police_stations
    WHERE status = 'approved'
      AND is_active = 1
      AND province = ?
  ");
  $stmt->execute([$province]);

  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDist = null;

  foreach ($stations as $s) {
    if (!is_numeric($s["lat"]) || !is_numeric($s["lng"])) continue;

    $d = haversine_distance($lat, $lng, (float)$s["lat"], (float)$s["lng"]);

    if ($nearestDist === null || $d < $nearestDist) {
      $nearestDist = $d;
      $nearest = $s;
      $nearest["distance_m"] = (int)round($d);
    }
  }

  return $nearest;
}

/**
 * Get nearest station within a specific city/municipality
 */
function find_nearest_station_in_city(PDO $pdo, float $lat, float $lng, ?string $province, ?string $city): ?array {
  if (!$province || !$city) return null;

  $stmt = $pdo->prepare("
    SELECT *
    FROM police_stations
    WHERE status = 'approved'
      AND is_active = 1
      AND province = ?
      AND city_municipality = ?
  ");
  $stmt->execute([$province, $city]);

  $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDist = null;

  foreach ($stations as $s) {
    if (!is_numeric($s["lat"]) || !is_numeric($s["lng"])) continue;

    $d = haversine_distance($lat, $lng, (float)$s["lat"], (float)$s["lng"]);

    if ($nearestDist === null || $d < $nearestDist) {
      $nearestDist = $d;
      $nearest = $s;
      $nearest["distance_m"] = (int)round($d);
    }
  }

  return $nearest;
}

/**
 * 🔴 PANIC ASSIGNMENT (EMERGENCY)
 * → nearest station in province (FAST RESPONSE)
 */
function assign_panic_station(PDO $pdo, float $lat, float $lng, ?string $province): ?array {
  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);

  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_NEAREST";
    return $provinceMatch;
  }

  return null;
}

/**
 * 🟡 INCIDENT ASSIGNMENT (STRICT JURISDICTION)
 * → city first, fallback to province
 */
function assign_incident_station(PDO $pdo, float $lat, float $lng, ?string $province, ?string $city): ?array {

  // 1. STRICT city match
  $cityMatch = find_nearest_station_in_city($pdo, $lat, $lng, $province, $city);
  if ($cityMatch) {
    $cityMatch["_assignment_rule"] = "CITY_STRICT";
    return $cityMatch;
  }

  // 2. fallback to province
  $provinceMatch = find_nearest_station_in_province($pdo, $lat, $lng, $province);
  if ($provinceMatch) {
    $provinceMatch["_assignment_rule"] = "PROVINCE_FALLBACK";
    return $provinceMatch;
  }

  return null;
}