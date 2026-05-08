<?php

function area_norm(?string $value): ?string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value === "" ? null : $value;
}

function area_same_text(?string $a, ?string $b): bool {
  $a = strtolower(trim((string)($a ?? "")));
  $b = strtolower(trim((string)($b ?? "")));
  return $a !== "" && $b !== "" && $a === $b;
}

/**
 * Returns all assigned barangays of a station.
 */
function station_area_get_barangays(PDO $pdo, int $stationId): array {
  if ($stationId <= 0) return [];

  $stmt = $pdo->prepare("
    SELECT
      id,
      station_id,
      province,
      city_municipality,
      barangay,
      created_at
    FROM station_area_barangays
    WHERE station_id = ?
    ORDER BY barangay ASC
  ");
  $stmt->execute([$stationId]);

  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns true if this station has at least one assigned barangay.
 * If false, the station is treated as responsible for the whole city/municipality.
 */
function station_area_has_assignments(PDO $pdo, int $stationId): bool {
  if ($stationId <= 0) return false;

  $stmt = $pdo->prepare("
    SELECT id
    FROM station_area_barangays
    WHERE station_id = ?
    LIMIT 1
  ");
  $stmt->execute([$stationId]);

  return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Find the station responsible for a reported barangay.
 *
 * Rule:
 * 1. If a barangay is assigned in station_area_barangays, use that station.
 * 2. If no barangay assignment exists for that city/municipality, fall back to nearest city station.
 * 3. If city has assignments but the barangay is unassigned, fall back to nearest station in city.
 *    This avoids losing reports when barangay geocoding spelling is different.
 */
function find_station_by_area_of_responsibility(
  PDO $pdo,
  float $lat,
  float $lng,
  ?string $province,
  ?string $cityMunicipality,
  ?string $barangay
): ?array {
  $province = area_norm($province);
  $cityMunicipality = area_norm($cityMunicipality);
  $barangay = area_norm($barangay);

  if (!$province || !$cityMunicipality) {
    return null;
  }

  if ($barangay) {
    $stmt = $pdo->prepare("
      SELECT
        ps.*,
        sab.barangay AS assigned_area_barangay,
        0 AS distance_m
      FROM station_area_barangays sab
      INNER JOIN police_stations ps
        ON ps.id = sab.station_id
      WHERE ps.verification_status = 'approved'
        AND ps.is_active = 1
        AND LOWER(TRIM(sab.province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(sab.city_municipality)) = LOWER(TRIM(?))
        AND LOWER(TRIM(sab.barangay)) = LOWER(TRIM(?))
      LIMIT 1
    ");
    $stmt->execute([$province, $cityMunicipality, $barangay]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $row["_assignment_rule"] = "AREA_OF_RESPONSIBILITY_BARANGAY";
      return $row;
    }
  }

  /*
   * If there are no barangay assignments at all in this city,
   * default behavior remains: nearest station inside the city.
   */
  $hasCityAssignmentsStmt = $pdo->prepare("
    SELECT sab.id
    FROM station_area_barangays sab
    INNER JOIN police_stations ps
      ON ps.id = sab.station_id
    WHERE ps.verification_status = 'approved'
      AND ps.is_active = 1
      AND LOWER(TRIM(sab.province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(sab.city_municipality)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $hasCityAssignmentsStmt->execute([$province, $cityMunicipality]);
  $hasCityAssignments = (bool)$hasCityAssignmentsStmt->fetch(PDO::FETCH_ASSOC);

  $stmt = $pdo->prepare("
    SELECT
      ps.*,
      ROUND(
        6371000 * 2 * ASIN(
          SQRT(
            POWER(SIN(RADIANS(ps.lat - ?) / 2), 2) +
            COS(RADIANS(?)) * COS(RADIANS(ps.lat)) *
            POWER(SIN(RADIANS(ps.lng - ?) / 2), 2)
          )
        )
      ) AS distance_m
    FROM police_stations ps
    WHERE ps.verification_status = 'approved'
      AND ps.is_active = 1
      AND LOWER(TRIM(ps.province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(ps.city_municipality)) = LOWER(TRIM(?))
    ORDER BY distance_m ASC
    LIMIT 1
  ");
  $stmt->execute([$lat, $lat, $lng, $province, $cityMunicipality]);
  $fallback = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($fallback) {
    $fallback["_assignment_rule"] = $hasCityAssignments
      ? "AREA_UNASSIGNED_BARANGAY_CITY_FALLBACK"
      : "NO_AREA_ASSIGNED_CITY_DEFAULT";

    return $fallback;
  }

  return null;
}