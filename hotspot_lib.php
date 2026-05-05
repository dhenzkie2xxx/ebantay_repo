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

/**
 * Rule-based crime severity weight.
 * This follows a Crime Harm Index / Crime Severity Score style weighting:
 * SS = Σ(n_i * w_i)
 */
function hotspot_crime_weight(?string $incidentType, ?string $crimeCategory = null, $storedSeverity = null): float {
  if ($storedSeverity !== null && is_numeric($storedSeverity) && (float)$storedSeverity > 0) {
    return (float)$storedSeverity;
  }

  $name = strtoupper(trim((string)$incidentType));
  $category = strtoupper(trim((string)$crimeCategory));

  $weights = [
    "MURDER" => 10,
    "HOMICIDE" => 10,
    "RAPE" => 9,
    "ROBBERY" => 8,
    "CARNAPPING" => 8,
    "SERIOUS PHYSICAL INJURY" => 7,
    "PHYSICAL INJURY" => 6,
    "DRUG" => 6,
    "DRUG-RELATED" => 6,
    "DRUG RELATED" => 6,
    "BURGLARY" => 6,
    "ASSAULT" => 5,
    "THEFT" => 3,
    "THIEF" => 3,
    "VANDALISM" => 2,
    "PUBLIC DISTURBANCE" => 1
  ];

  foreach ($weights as $keyword => $weight) {
    if ($name === $keyword || str_contains($name, $keyword)) {
      return (float)$weight;
    }
  }

  if ($category === "INDEX") return 5.0;
  if ($category === "SPECIAL_LAW") return 4.0;
  if ($category === "NON_INDEX") return 3.0;

  return 2.0;
}

function hotspot_compute_color($incidentCount, $panicCount, $severityScoreTotal = 0, $maxCrimeWeight = 0) {
  $incidentCount = (int)$incidentCount;
  $panicCount = (int)$panicCount;
  $severityScoreTotal = (float)$severityScoreTotal;
  $maxCrimeWeight = (float)$maxCrimeWeight;

  /*
    Weighted hotspot risk scoring:
    - Incident count = frequency/density factor
    - Severity total = accumulated crime severity
    - Max crime weight = high-impact crime boost
    - Panic count = emergency signal boost
  */
  $score =
    ($incidentCount * 1.5) +
    ($severityScoreTotal * 1.8) +
    ($maxCrimeWeight * 2.2) +
    ($panicCount * 12);

  if ($score >= 55) return "red";
  if ($score >= 30) return "orange";
  if ($score >= 15) return "yellow";
  if ($incidentCount >= 1) return "green";

  return "none";
}

function hotspot_compute_risk_level($color) {
  $color = strtolower(trim((string)$color));

  if ($color === "red") return "HIGH";
  if ($color === "orange") return "MEDIUM";
  if ($color === "yellow") return "LOW";
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
      incident_type,
      crime_category,
      severity_score,
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
      AND verification_status = 'VERIFIED'
      AND incident_phase IN (
        'RESOLVED',
        'BLOTTERED',
        'UNDER_INVESTIGATION',
        'FILED_IN_COURT'
      )
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
    $severityScoreTotal = 0.0;
    $maxCrimeWeight = 0.0;
    $lastDetected = null;

    foreach ($incidentRows as $r) {
      $d = hotspot_distance_meters($hLat, $hLng, (float)$r["lat"], (float)$r["lng"]);

      if ($d <= $radius) {
        $incidentCount++;

        $weight = hotspot_crime_weight(
          $r["incident_type"] ?? null,
          $r["crime_category"] ?? null,
          $r["severity_score"] ?? null
        );

        $severityScoreTotal += $weight;
        if ($weight > $maxCrimeWeight) {
          $maxCrimeWeight = $weight;
        }

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

    $color = hotspot_compute_color($incidentCount, $panicCount, $severityScoreTotal, $maxCrimeWeight);
    $riskLevel = hotspot_compute_risk_level($color);

    $pointCount = $incidentCount + $panicCount;
    $areaM2 = hotspot_area_m2($radius);
    $densityValue = hotspot_density_value($pointCount, $radius);
    $densityPerKm2 = hotspot_density_per_km2($pointCount, $radius);
    $densityLevel = hotspot_density_level($densityPerKm2);

    $score = $severityScoreTotal + $panicScore;

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
      "severity_score_total" => round($severityScoreTotal, 2),
      "max_crime_weight" => round($maxCrimeWeight, 2),
      "score" => round($score, 2),
      "area_m2" => round($areaM2, 2),
      "density_value" => round($densityValue, 8),
      "density_per_km2" => round($densityPerKm2, 2),
      "density_level" => $densityLevel,
      "last_detected_at" => $lastDetected,
      "created_at" => $h["created_at"],
    ];
  }

  usort($out, function ($a, $b) {
    $rank = ["red" => 5, "orange" => 4, "yellow" => 3, "green" => 2, "none" => 1];
    $ra = $rank[$a["highlight_color"]] ?? 0;
    $rb = $rank[$b["highlight_color"]] ?? 0;
    if ($ra !== $rb) return $rb <=> $ra;

    $severityCmp = ($b["severity_score_total"] ?? 0) <=> ($a["severity_score_total"] ?? 0);
    if ($severityCmp !== 0) return $severityCmp;

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

if (!function_exists("hotspot_auto_create_from_incident")) {
  function hotspot_auto_create_from_incident(
    PDO $pdo,
    int $incidentId,
    int $days = 30,
    int $radiusM = 500,
    int $minIncidents = 2
  ): array {
    $days = max(1, min(365, $days));
    $radiusM = max(100, $radiusM);
    $minIncidents = max(2, $minIncidents);

    $stmt = $pdo->prepare("
      SELECT
        id,
        incident_type,
        crime_category,
        severity_score,
        lat,
        lng,
        region,
        province,
        city_municipality,
        barangay,
        date_reported
      FROM incident_reports
      WHERE id = ?
        AND lat IS NOT NULL
        AND lng IS NOT NULL
        AND verification_status = 'VERIFIED'
        AND incident_phase IN (
          'RESOLVED',
          'BLOTTERED',
          'UNDER_INVESTIGATION',
          'FILED_IN_COURT'
        )
      LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$base) {
      return [
        "ok" => false,
        "created" => false,
        "updated" => false,
        "message" => "Incident is not eligible for hotspot detection."
      ];
    }

    $baseLat = (float)$base["lat"];
    $baseLng = (float)$base["lng"];
    $province = trim((string)($base["province"] ?? ""));
    $city = trim((string)($base["city_municipality"] ?? ""));

    /*
      First: check if an active hotspot already exists near this incident.
      If yes, attach the incident to that hotspot and update timestamp.
    */
    $existingStmt = $pdo->prepare("
      SELECT
        id,
        lat,
        lng,
        radius_m
      FROM crime_hotspots
      WHERE active = 1
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
    ");
    $existingStmt->execute([$province, $city]);
    $existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($existing as $h) {
      $hLat = (float)$h["lat"];
      $hLng = (float)$h["lng"];
      $hRadius = max($radiusM, (int)($h["radius_m"] ?? $radiusM));
      $dist = hotspot_distance_meters($baseLat, $baseLng, $hLat, $hLng);

      if ($dist <= $hRadius) {
        $pdo->prepare("
          UPDATE crime_hotspots
          SET last_detected_at = UTC_TIMESTAMP()
          WHERE id = ?
        ")->execute([(int)$h["id"]]);

        $pdo->prepare("
          UPDATE incident_reports
          SET
            is_hotspot_related = 1,
            hotspot_id = ?,
            risk_status = 'RISK',
            risk_distance_m = ?,
            risk_radius_m = ?
          WHERE id = ?
        ")->execute([
          (int)$h["id"],
          (int)round($dist),
          $hRadius,
          $incidentId
        ]);

        return [
          "ok" => true,
          "created" => false,
          "updated" => true,
          "hotspot_id" => (int)$h["id"],
          "message" => "Incident attached to existing hotspot."
        ];
      }
    }

    /*
      Second: collect nearby verified/resolved/blottered incidents.
    */
    $nearStmt = $pdo->prepare("
      SELECT
        id,
        incident_type,
        crime_category,
        severity_score,
        lat,
        lng,
        region,
        province,
        city_municipality,
        barangay,
        date_reported
      FROM incident_reports
      WHERE lat IS NOT NULL
        AND lng IS NOT NULL
        AND verification_status = 'VERIFIED'
        AND incident_phase IN (
          'RESOLVED',
          'BLOTTERED',
          'UNDER_INVESTIGATION',
          'FILED_IN_COURT'
        )
        AND LOWER(TRIM(province)) = LOWER(TRIM(?))
        AND LOWER(TRIM(city_municipality)) = LOWER(TRIM(?))
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
      ORDER BY date_reported DESC
      LIMIT 500
    ");
    $nearStmt->execute([$province, $city, $days]);
    $rows = $nearStmt->fetchAll(PDO::FETCH_ASSOC);

    $cluster = [];
    $severityTotal = 0.0;
    $maxWeight = 0.0;
    $latSum = 0.0;
    $lngSum = 0.0;
    $barangayCounts = [];

    foreach ($rows as $r) {
      $dist = hotspot_distance_meters(
        $baseLat,
        $baseLng,
        (float)$r["lat"],
        (float)$r["lng"]
      );

      if ($dist <= $radiusM) {
        $cluster[] = [
          "row" => $r,
          "distance_m" => $dist
        ];

        $weight = hotspot_crime_weight(
          $r["incident_type"] ?? "",
          $r["crime_category"] ?? "",
          $r["severity_score"] ?? null
        );

        $severityTotal += $weight;
        $maxWeight = max($maxWeight, $weight);

        $latSum += (float)$r["lat"];
        $lngSum += (float)$r["lng"];

        $bgy = trim((string)($r["barangay"] ?? ""));
        if ($bgy !== "") {
          $barangayCounts[$bgy] = ($barangayCounts[$bgy] ?? 0) + 1;
        }
      }
    }

    $incidentCount = count($cluster);

    if ($incidentCount < $minIncidents && $maxWeight < 8) {
      return [
        "ok" => true,
        "created" => false,
        "updated" => false,
        "incident_count" => $incidentCount,
        "message" => "Not enough nearby incidents to create a hotspot."
      ];
    }

    arsort($barangayCounts);
    $dominantBarangay = array_key_first($barangayCounts) ?: ($base["barangay"] ?? null);

    $centerLat = $latSum / max(1, $incidentCount);
    $centerLng = $lngSum / max(1, $incidentCount);

    $color = hotspot_compute_color($incidentCount, 0, $severityTotal, $maxWeight);
    $riskLevel = hotspot_compute_risk_level($color);

    $name = trim(
      "Auto Hotspot - " .
      ($dominantBarangay ?: "Unknown Barangay") .
      " / " .
      ($base["city_municipality"] ?: "Unknown City")
    );

    $insert = $pdo->prepare("
      INSERT INTO crime_hotspots
      (
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
        active,
        last_detected_at,
        created_at
      )
      VALUES
      (
        ?, ?, ?, ?, ?, ?, ?, ?, 'CRIME_CLUSTER', ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
      )
    ");

    $insert->execute([
      $name,
      $base["region"] ?? null,
      $base["province"] ?? null,
      $base["city_municipality"] ?? null,
      $dominantBarangay,
      $centerLat,
      $centerLng,
      $radiusM,
      $riskLevel
    ]);

    $hotspotId = (int)$pdo->lastInsertId();

    $updateIncident = $pdo->prepare("
      UPDATE incident_reports
      SET
        is_hotspot_related = 1,
        hotspot_id = ?,
        risk_status = 'RISK',
        risk_distance_m = ?,
        risk_radius_m = ?
      WHERE id = ?
    ");

    foreach ($cluster as $c) {
      $updateIncident->execute([
        $hotspotId,
        (int)round($c["distance_m"]),
        $radiusM,
        (int)$c["row"]["id"]
      ]);
    }

    return [
      "ok" => true,
      "created" => true,
      "updated" => false,
      "hotspot_id" => $hotspotId,
      "incident_count" => $incidentCount,
      "severity_score_total" => round($severityTotal, 2),
      "max_crime_weight" => round($maxWeight, 2),
      "risk_level" => $riskLevel,
      "message" => "New hotspot automatically created."
    ];
  }
}

if (!function_exists("recalc_hotspots_after_incident_save")) {
  function recalc_hotspots_after_incident_save(PDO $pdo, int $incidentId): array {
    return hotspot_auto_create_from_incident($pdo, $incidentId, 30, 500, 2);
  }
}

if (!function_exists("queue_incident_hotspot_alerts")) {
  function queue_incident_hotspot_alerts(PDO $pdo, int $incidentId): array {
    return [
      "created" => 0,
      "targets" => []
    ];
  }
}