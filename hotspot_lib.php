<?php

function hotspot_distance_meters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a = sin($dLat / 2) * sin($dLat / 2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

function hotspot_normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function hotspot_compute_color($incidentCount, $panicCount) {
  if ($incidentCount >= 3 || $panicCount >= 1) return "red";
  if ($incidentCount >= 2) return "orange";
  if ($incidentCount >= 1) return "green";
  return "none";
}

function hotspot_compute_risk_level($color) {
  if ($color === "red") return "HIGH";
  if ($color === "orange") return "MEDIUM";
  if ($color === "green") return "LOW";
  return "LOW";
}

function hotspot_area_m2(int $radiusM): float {
  $radiusM = max(1, (int)$radiusM);
  return M_PI * pow($radiusM, 2);
}

function hotspot_density_value(int $pointCount, int $radiusM): float {
  $area = hotspot_area_m2($radiusM);
  if ($area <= 0) return 0.0;
  return $pointCount / $area;
}

function hotspot_density_per_km2(int $pointCount, int $radiusM): float {
  return hotspot_density_value($pointCount, $radiusM) * 1000000;
}

function hotspot_density_level(float $densityPerKm2): string {
  if ($densityPerKm2 >= 40) return "HIGH";
  if ($densityPerKm2 >= 15) return "MEDIUM";
  return "LOW";
}

function hotspot_base_rows(PDO $pdo): array {
  $stmt = $pdo->query("
    SELECT
      id,
      name,
      region,
      province,
      city_municipality,
      barangay,
      lat,
      lng,
      radius_m,
      hotspot_type,
      risk_level,
      last_detected_at,
      created_at
    FROM crime_hotspots
    WHERE active = 1
    ORDER BY id DESC
  ");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hotspot_append_incident_scope_filter(
  string &$sql,
  array &$params,
  string $role,
  ?string $provinceFilter,
  ?string $cityFilter,
  ?int $userId
): void {
  if ($role === "super_admin") {
    return;
  }

  if ($role === "admin") {
    if ($provinceFilter !== null && $cityFilter !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
    }
    return;
  }

  if ($role === "citizen") {
    if ($provinceFilter !== null && $cityFilter !== null && $userId !== null) {
      $sql .= "
        AND (
          (
            LOWER(TRIM(province)) = LOWER(TRIM(?))
            AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
          )
          OR reporter_user_id = ?
        )
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
      $params[] = $userId;
    } elseif ($provinceFilter !== null && $cityFilter !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
    } elseif ($userId !== null) {
      $sql .= " AND reporter_user_id = ? ";
      $params[] = $userId;
    }
    return;
  }

  if ($provinceFilter !== null && $cityFilter !== null) {
    $sql .= "
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ";
    $params[] = $provinceFilter;
    $params[] = $cityFilter;
  }
}

function hotspot_append_panic_scope_filter(
  string &$sql,
  array &$params,
  string $role,
  ?string $provinceFilter,
  ?string $cityFilter,
  ?int $userId
): void {
  if ($role === "super_admin") {
    return;
  }

  if ($role === "admin") {
    if ($provinceFilter !== null && $cityFilter !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
    }
    return;
  }

  if ($role === "citizen") {
    if ($provinceFilter !== null && $cityFilter !== null && $userId !== null) {
      $sql .= "
        AND (
          (
            LOWER(TRIM(province)) = LOWER(TRIM(?))
            AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
          )
          OR user_id = ?
        )
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
      $params[] = $userId;
    } elseif ($provinceFilter !== null && $cityFilter !== null) {
      $sql .= "
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
      ";
      $params[] = $provinceFilter;
      $params[] = $cityFilter;
    } elseif ($userId !== null) {
      $sql .= " AND user_id = ? ";
      $params[] = $userId;
    }
    return;
  }

  if ($provinceFilter !== null && $cityFilter !== null) {
    $sql .= "
      AND LOWER(TRIM(province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ";
    $params[] = $provinceFilter;
    $params[] = $cityFilter;
  }
}

function hotspot_incident_rows(
  PDO $pdo,
  int $days = 30,
  ?string $provinceFilter = null,
  ?string $cityFilter = null,
  string $role = "public",
  ?int $userId = null
): array {
  $sql = "
    SELECT
      id,
      reporter_user_id,
      lat,
      lng,
      province,
      city_municipality,
      barangay,
      date_reported
    FROM incident_reports
    WHERE
      lat IS NOT NULL
      AND lng IS NOT NULL
      AND incident_phase <> 'REJECTED'
      AND verification_status = 'VERIFIED'
      AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ";
  $params = [$days];

  hotspot_append_incident_scope_filter($sql, $params, $role, $provinceFilter, $cityFilter, $userId);

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hotspot_panic_rows(
  PDO $pdo,
  int $days = 30,
  ?string $provinceFilter = null,
  ?string $cityFilter = null,
  string $role = "public",
  ?int $userId = null
): array {
  $sql = "
    SELECT
      id,
      user_id,
      lat,
      lng,
      province,
      city_municipality,
      barangay,
      level,
      created_at,
      status
    FROM panic_requests
    WHERE
      lat IS NOT NULL
      AND lng IS NOT NULL
      AND status <> 'resolved'
      AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ";
  $params = [$days];

  hotspot_append_panic_scope_filter($sql, $params, $role, $provinceFilter, $cityFilter, $userId);

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_computed_hotspots(
  PDO $pdo,
  int $days = 30,
  ?string $provinceFilter = null,
  ?string $cityFilter = null,
  string $role = "public",
  ?int $userId = null
): array {
  $days = max(1, min(365, $days));
  $provinceFilter = hotspot_normalize_scope_value($provinceFilter);
  $cityFilter = hotspot_normalize_scope_value($cityFilter);

  $hotspots = hotspot_base_rows($pdo);
  $incidentRows = hotspot_incident_rows($pdo, $days, $provinceFilter, $cityFilter, $role, $userId);
  $panicRows = hotspot_panic_rows($pdo, $days, $provinceFilter, $cityFilter, $role, $userId);

  $out = [];

  foreach ($hotspots as $h) {
    $hLat = (float)$h["lat"];
    $hLng = (float)$h["lng"];
    $radius = max(1, (int)$h["radius_m"]);

    $incidentCount = 0;
    $panicCount = 0;
    $panicScore = 0;
    $lastDetected = null;

    foreach ($incidentRows as $r) {
      $d = hotspot_distance_meters($hLat, $hLng, (float)$r["lat"], (float)$r["lng"]);
      if ($d <= $radius) {
        $incidentCount++;
        if ($lastDetected === null || strtotime($r["date_reported"]) > strtotime($lastDetected)) {
          $lastDetected = $r["date_reported"];
        }
      }
    }

    foreach ($panicRows as $p) {
      $d = hotspot_distance_meters($hLat, $hLng, (float)$p["lat"], (float)$p["lng"]);
      if ($d <= $radius) {
        $panicCount++;
        $panicScore += (($p["level"] ?? "") === "urgent") ? 2 : 1;
        if ($lastDetected === null || strtotime($p["created_at"]) > strtotime($lastDetected)) {
          $lastDetected = $p["created_at"];
        }
      }
    }

    if (($provinceFilter !== null && $cityFilter !== null) && $incidentCount === 0 && $panicCount === 0) {
      continue;
    }

    $color = hotspot_compute_color($incidentCount, $panicCount);
    $riskLevel = hotspot_compute_risk_level($color);
    $score = $incidentCount + $panicScore;

    $pointCount = $incidentCount + $panicCount;
    $areaM2 = hotspot_area_m2($radius);
    $densityValue = hotspot_density_value($pointCount, $radius);
    $densityPerKm2 = hotspot_density_per_km2($pointCount, $radius);
    $densityLevel = hotspot_density_level($densityPerKm2);

    $out[] = [
      "id" => (int)$h["id"],
      "name" => $h["name"],
      "region" => $h["region"],
      "province" => $h["province"],
      "city_municipality" => $h["city_municipality"],
      "barangay" => $h["barangay"],
      "lat" => $hLat,
      "lng" => $hLng,
      "radius_m" => $radius,
      "hotspot_type" => $h["hotspot_type"],
      "risk_level" => $riskLevel,
      "highlight_color" => $color,
      "incident_count" => $incidentCount,
      "panic_count" => $panicCount,
      "panic_score" => $panicScore,
      "point_count" => $pointCount,
      "score" => $score,
      "area_m2" => round($areaM2, 2),
      "density_value" => round($densityValue, 8),
      "density_per_km2" => round($densityPerKm2, 2),
      "density_level" => $densityLevel,
      "last_detected_at" => $lastDetected,
      "created_at" => $h["created_at"],
    ];
  }

  usort($out, function ($a, $b) {
    $rank = ["red" => 4, "orange" => 3, "green" => 2, "none" => 1];
    $ra = $rank[$a["highlight_color"]] ?? 0;
    $rb = $rank[$b["highlight_color"]] ?? 0;
    if ($ra !== $rb) return $rb <=> $ra;

    $densityCmp = ($b["density_per_km2"] ?? 0) <=> ($a["density_per_km2"] ?? 0);
    if ($densityCmp !== 0) return $densityCmp;

    return ($b["score"] ?? 0) <=> ($a["score"] ?? 0);
  });

  return $out;
}

function find_nearest_hotspot(array $hotspots, float $lat, float $lng): ?array {
  $nearest = null;
  $nearestDistance = null;

  foreach ($hotspots as $h) {
    if (!isset($h["lat"], $h["lng"])) continue;

    $d = hotspot_distance_meters(
      $lat,
      $lng,
      (float)$h["lat"],
      (float)$h["lng"]
    );

    if ($nearestDistance === null || $d < $nearestDistance) {
      $nearestDistance = $d;
      $nearest = $h;
      $nearest["distance_m"] = (int)round($d);
      $nearest["inside_radius"] = $d <= (float)($h["radius_m"] ?? 0);
    }
  }

  return $nearest;
}