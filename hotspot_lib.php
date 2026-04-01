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

function hotspot_incident_rows(PDO $pdo, int $days = 30, ?string $provinceFilter = null): array {
  $sql = "
    SELECT
      lat,
      lng,
      province,
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

  if ($provinceFilter !== null) {
    $sql .= " AND LOWER(TRIM(province)) = LOWER(TRIM(?)) ";
    $params[] = $provinceFilter;
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hotspot_panic_rows(PDO $pdo, int $days = 30, ?string $provinceFilter = null): array {
  $sql = "
    SELECT
      lat,
      lng,
      province,
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

  if ($provinceFilter !== null) {
    $sql .= " AND LOWER(TRIM(province)) = LOWER(TRIM(?)) ";
    $params[] = $provinceFilter;
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_computed_hotspots(PDO $pdo, int $days = 30, ?string $provinceFilter = null): array {
  $days = max(1, min(365, $days));
  $provinceFilter = hotspot_normalize_scope_value($provinceFilter);

  $hotspots = hotspot_base_rows($pdo);
  $incidentRows = hotspot_incident_rows($pdo, $days, $provinceFilter);
  $panicRows = hotspot_panic_rows($pdo, $days, $provinceFilter);

  $out = [];

  foreach ($hotspots as $h) {
    $hLat = (float)$h["lat"];
    $hLng = (float)$h["lng"];
    $radius = (int)$h["radius_m"];

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

    if ($provinceFilter !== null && $incidentCount === 0 && $panicCount === 0) {
      continue;
    }

    $color = hotspot_compute_color($incidentCount, $panicCount);
    $riskLevel = hotspot_compute_risk_level($color);
    $score = $incidentCount + $panicScore;

    $out[] = [
      "id" => (int)$h["id"],
      "name" => $h["name"],
      "lat" => $hLat,
      "lng" => $hLng,
      "radius_m" => $radius,
      "hotspot_type" => $h["hotspot_type"],
      "risk_level" => $riskLevel,
      "highlight_color" => $color,
      "incident_count" => $incidentCount,
      "panic_count" => $panicCount,
      "panic_score" => $panicScore,
      "score" => $score,
      "last_detected_at" => $lastDetected,
      "created_at" => $h["created_at"],
    ];
  }

  usort($out, function ($a, $b) {
    $rank = ["red" => 4, "orange" => 3, "green" => 2, "none" => 1];
    $ra = $rank[$a["highlight_color"]] ?? 0;
    $rb = $rank[$b["highlight_color"]] ?? 0;
    if ($ra !== $rb) return $rb <=> $ra;
    return ($b["score"] ?? 0) <=> ($a["score"] ?? 0);
  });

  return $out;
}

function find_nearest_hotspot(array $hotspots, float $lat, float $lng): ?array {
  $nearest = null;
  $nearestD = null;

  foreach ($hotspots as $h) {
    $d = hotspot_distance_meters($lat, $lng, (float)$h["lat"], (float)$h["lng"]);
    if ($nearestD === null || $d < $nearestD) {
      $nearestD = $d;
      $nearest = $h;
      $nearest["distance_m"] = (int)round($d);
      $nearest["is_inside"] = $d <= (float)$h["radius_m"];
    }
  }

  return $nearest;
}

function hotspot_config(): array {
  return [
    "radius_m" => 250,
    "days" => 30,
    "incident_threshold" => 3,
    "merge_distance_m" => 250,
  ];
}

function hotspot_detect_context(PDO $pdo, float $lat, float $lng, ?int $excludeIncidentId = null): array {
  $cfg = hotspot_config();
  $days = (int)$cfg["days"];
  $radius = (int)$cfg["radius_m"];

  $incidentSql = "
    SELECT
      id,
      title,
      incident_type,
      lat,
      lng,
      barangay,
      city_municipality,
      province,
      date_reported
    FROM incident_reports
    WHERE
      verification_status = 'VERIFIED'
      AND incident_phase <> 'REJECTED'
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ";

  $incidentParams = [$days];
  if ($excludeIncidentId !== null && $excludeIncidentId > 0) {
    $incidentSql .= " AND id <> ? ";
    $incidentParams[] = $excludeIncidentId;
  }

  $stmt = $pdo->prepare($incidentSql);
  $stmt->execute($incidentParams);
  $incidentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearIncidents = [];
  foreach ($incidentRows as $r) {
    $d = hotspot_distance_meters($lat, $lng, (float)$r["lat"], (float)$r["lng"]);
    if ($d <= $radius) {
      $r["distance_m"] = (int)round($d);
      $nearIncidents[] = $r;
    }
  }

  $panicStmt = $pdo->prepare("
    SELECT id, level, lat, lng, created_at, province
    FROM panic_requests
    WHERE
      status <> 'resolved'
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ");
  $panicStmt->execute([$days]);
  $panicRows = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

  $nearPanics = [];
  foreach ($panicRows as $p) {
    $d = hotspot_distance_meters($lat, $lng, (float)$p["lat"], (float)$p["lng"]);
    if ($d <= $radius) {
      $p["distance_m"] = (int)round($d);
      $nearPanics[] = $p;
    }
  }

  return [
    "radius_m" => $radius,
    "incident_count" => count($nearIncidents),
    "panic_count" => count($nearPanics),
    "near_incidents" => $nearIncidents,
    "near_panics" => $nearPanics,
  ];
}

function hotspot_should_exist(array $ctx): bool {
  $cfg = hotspot_config();
  return
    ($ctx["incident_count"] ?? 0) >= (int)$cfg["incident_threshold"] ||
    ($ctx["panic_count"] ?? 0) >= 1;
}

function hotspot_determine_risk_level(array $ctx): string {
  $incidentCount = (int)($ctx["incident_count"] ?? 0);
  $panicCount = (int)($ctx["panic_count"] ?? 0);

  if ($incidentCount >= 3 || $panicCount >= 1) return "HIGH";
  if ($incidentCount >= 2) return "MEDIUM";
  return "LOW";
}

function hotspot_determine_type(array $ctx, ?string $fallbackIncidentType = null): string {
  $types = [];

  foreach (($ctx["near_incidents"] ?? []) as $r) {
    $t = trim((string)($r["incident_type"] ?? ""));
    if ($t !== "") {
      if (!isset($types[$t])) $types[$t] = 0;
      $types[$t]++;
    }
  }

  if (!empty($types)) {
    arsort($types);
    return (string)array_key_first($types);
  }

  return $fallbackIncidentType && trim($fallbackIncidentType) !== ""
    ? trim($fallbackIncidentType)
    : "General hotspot";
}

function hotspot_generate_name(array $incident, string $hotspotType): string {
  $barangay = trim((string)($incident["barangay"] ?? ""));
  $city = trim((string)($incident["city_municipality"] ?? ""));

  if ($barangay !== "") {
    return $hotspotType . " - " . $barangay;
  }
  if ($city !== "") {
    return $hotspotType . " - " . $city;
  }
  return $hotspotType . " - Auto Detected";
}

function hotspot_find_existing(PDO $pdo, float $lat, float $lng, array $incident, int $mergeDistanceM = 250): ?array {
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
      active,
      created_at,
      last_detected_at
    FROM crime_hotspots
    WHERE active = 1
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestD = null;

  foreach ($rows as $r) {
    if (!hotspot_same_scope($r, $incident)) {
      continue;
    }

    $d = hotspot_distance_meters($lat, $lng, (float)$r["lat"], (float)$r["lng"]);
    if ($d <= $mergeDistanceM && ($nearestD === null || $d < $nearestD)) {
      $nearest = $r;
      $nearestD = $d;
    }
  }

  if ($nearest) {
    $nearest["distance_m"] = (int)round($nearestD);
  }

  return $nearest;
}

function hotspot_create(PDO $pdo, array $incident, array $ctx): int {
  $cfg = hotspot_config();
  $riskLevel = hotspot_determine_risk_level($ctx);
  $hotspotType = hotspot_determine_type($ctx, $incident["incident_type"] ?? null);
  $name = hotspot_generate_name($incident, $hotspotType);

  $stmt = $pdo->prepare("
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
      active,
      hotspot_type,
      risk_level,
      last_detected_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())
  ");
  $stmt->execute([
    $name,
    hotspot_normalize_scope_value($incident["region"] ?? null),
    hotspot_normalize_scope_value($incident["province"] ?? null),
    hotspot_normalize_scope_value($incident["city_municipality"] ?? null),
    hotspot_normalize_scope_value($incident["barangay"] ?? null),
    $incident["lat"],
    $incident["lng"],
    $cfg["radius_m"],
    $hotspotType,
    $riskLevel
  ]);

  return (int)$pdo->lastInsertId();
}

function hotspot_update(PDO $pdo, int $hotspotId, array $incident, array $ctx): void {
  $cfg = hotspot_config();
  $riskLevel = hotspot_determine_risk_level($ctx);
  $hotspotType = hotspot_determine_type($ctx, $incident["incident_type"] ?? null);
  $name = hotspot_generate_name($incident, $hotspotType);

  $stmt = $pdo->prepare("
    UPDATE crime_hotspots
    SET
      name = ?,
      region = ?,
      province = ?,
      city_municipality = ?,
      barangay = ?,
      lat = ?,
      lng = ?,
      radius_m = ?,
      active = 1,
      hotspot_type = ?,
      risk_level = ?,
      last_detected_at = UTC_TIMESTAMP()
    WHERE id = ?
  ");
  $stmt->execute([
    $name,
    hotspot_normalize_scope_value($incident["region"] ?? null),
    hotspot_normalize_scope_value($incident["province"] ?? null),
    hotspot_normalize_scope_value($incident["city_municipality"] ?? null),
    hotspot_normalize_scope_value($incident["barangay"] ?? null),
    $incident["lat"],
    $incident["lng"],
    $cfg["radius_m"],
    $hotspotType,
    $riskLevel,
    $hotspotId
  ]);
}

function hotspot_assign_to_incident(PDO $pdo, int $incidentId, int $hotspotId, float $incidentLat, float $incidentLng): void {
  $stmt = $pdo->prepare("
    SELECT id, lat, lng, radius_m
    FROM crime_hotspots
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$hotspotId]);
  $h = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$h) return;

  $distanceM = (int)round(hotspot_distance_meters(
    $incidentLat,
    $incidentLng,
    (float)$h["lat"],
    (float)$h["lng"]
  ));

  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET
      is_hotspot_related = 1,
      hotspot_id = ?,
      risk_status = 'RISK',
      risk_distance_m = ?,
      risk_radius_m = ?
    WHERE id = ?
  ");
  $stmt->execute([
    $hotspotId,
    $distanceM,
    (int)$h["radius_m"],
    $incidentId
  ]);
}

function hotspot_unassign_from_incident(PDO $pdo, int $incidentId): void {
  $stmt = $pdo->prepare("
    UPDATE incident_reports
    SET
      is_hotspot_related = 0,
      hotspot_id = NULL,
      risk_status = 'SAFE',
      risk_distance_m = NULL,
      risk_radius_m = 250
    WHERE id = ?
  ");
  $stmt->execute([$incidentId]);
}

function hotspot_refresh_incident_link(PDO $pdo, int $incidentId): void {
  $stmt = $pdo->prepare("
  SELECT
    id,
    incident_type,
    lat,
    lng,
    region,
    barangay,
    city_municipality,
    province,
    verification_status,
    incident_phase
  FROM incident_reports
  WHERE id = ?
  LIMIT 1
");
  $stmt->execute([$incidentId]);
  $incident = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$incident) return;

  $lat = $incident["lat"] !== null ? (float)$incident["lat"] : null;
  $lng = $incident["lng"] !== null ? (float)$incident["lng"] : null;

  if (
    $lat === null ||
    $lng === null ||
    strtoupper((string)$incident["verification_status"]) !== "VERIFIED" ||
    strtoupper((string)$incident["incident_phase"]) === "REJECTED"
  ) {
    hotspot_unassign_from_incident($pdo, $incidentId);
    return;
  }

  $ctx = hotspot_detect_context($pdo, $lat, $lng, $incidentId);
  $ctx["incident_count"] = (int)$ctx["incident_count"] + 1;

  if (!hotspot_should_exist($ctx)) {
    hotspot_unassign_from_incident($pdo, $incidentId);
    return;
  }

  $cfg = hotspot_config();
  $existing = hotspot_find_existing($pdo, $lat, $lng, $incident, (int)$cfg["merge_distance_m"]);

  if ($existing) {
    hotspot_update($pdo, (int)$existing["id"], $incident, $ctx);
    hotspot_assign_to_incident($pdo, $incidentId, (int)$existing["id"], $lat, $lng);
    return;
  }

  $newHotspotId = hotspot_create($pdo, $incident, $ctx);
  hotspot_assign_to_incident($pdo, $incidentId, $newHotspotId, $lat, $lng);
}

function hotspot_refresh_nearby_links(PDO $pdo, float $lat, float $lng, int $radiusM = 500): void {
  $days = (int)(hotspot_config()["days"] ?? 30);

  $stmt = $pdo->prepare("
    SELECT id, lat, lng
    FROM incident_reports
    WHERE
      lat IS NOT NULL
      AND lng IS NOT NULL
      AND verification_status = 'VERIFIED'
      AND incident_phase <> 'REJECTED'
      AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ");
  $stmt->execute([$days]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $d = hotspot_distance_meters($lat, $lng, (float)$r["lat"], (float)$r["lng"]);
    if ($d <= $radiusM) {
      hotspot_refresh_incident_link($pdo, (int)$r["id"]);
    }
  }
}

function hotspot_deactivate_orphan_hotspots(PDO $pdo): void {
  $stmt = $pdo->query("
    SELECT h.id
    FROM crime_hotspots h
    LEFT JOIN incident_reports r
      ON r.hotspot_id = h.id
      AND r.verification_status = 'VERIFIED'
      AND r.incident_phase <> 'REJECTED'
      AND r.is_hotspot_related = 1
    WHERE h.active = 1
    GROUP BY h.id
    HAVING COUNT(r.id) = 0
  ");
  $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

  if (!$ids) return;

  $upd = $pdo->prepare("
    UPDATE crime_hotspots
    SET active = 0
    WHERE id = ?
  ");

  foreach ($ids as $id) {
    $upd->execute([(int)$id]);
  }
}

function hotspot_norm_text($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : mb_strtolower($value);
}

function hotspot_same_scope(array $hotspotRow, array $incident): bool {
  $hProvince = hotspot_norm_text($hotspotRow["province"] ?? null);
  $iProvince = hotspot_norm_text($incident["province"] ?? null);

  if ($hProvince !== null && $iProvince !== null && $hProvince !== $iProvince) {
    return false;
  }

  $hCity = hotspot_norm_text($hotspotRow["city_municipality"] ?? null);
  $iCity = hotspot_norm_text($incident["city_municipality"] ?? null);

  if ($hCity !== null && $iCity !== null && $hCity !== $iCity) {
    return false;
  }

  $hBarangay = hotspot_norm_text($hotspotRow["barangay"] ?? null);
  $iBarangay = hotspot_norm_text($incident["barangay"] ?? null);

  if ($hBarangay !== null && $iBarangay !== null && $hBarangay !== $iBarangay) {
    return false;
  }

  return true;
}

function get_users_inside_hotspot(PDO $pdo, int $hotspotId, int $locationFreshMinutes = 30): array {
  $stmt = $pdo->prepare("
    SELECT id, lat, lng, radius_m, name, risk_level
    FROM crime_hotspots
    WHERE id = ? AND active = 1
    LIMIT 1
  ");
  $stmt->execute([$hotspotId]);
  $hotspot = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$hotspot) return [];

  $hLat = (float)$hotspot["lat"];
  $hLng = (float)$hotspot["lng"];
  $radius = (int)$hotspot["radius_m"];

  $locStmt = $pdo->prepare("
    SELECT ul.user_id, ul.lat, ul.lng, ul.created_at, u.firstname, u.lastname, u.email
    FROM user_locations ul
    JOIN users u ON u.id = ul.user_id
    JOIN (
      SELECT user_id, MAX(created_at) AS latest_created_at
      FROM user_locations
      WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
      GROUP BY user_id
    ) latest
      ON latest.user_id = ul.user_id
     AND latest.latest_created_at = ul.created_at
    WHERE u.valid = 'valid'
  ");
  $locStmt->execute([$locationFreshMinutes]);
  $rows = $locStmt->fetchAll(PDO::FETCH_ASSOC);

  $users = [];
  foreach ($rows as $r) {
    $d = hotspot_distance_meters(
      $hLat,
      $hLng,
      (float)$r["lat"],
      (float)$r["lng"]
    );

    if ($d <= $radius) {
      $users[] = [
        "user_id" => (int)$r["user_id"],
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"],
        "distance_m" => (int)round($d),
      ];
    }
  }

  return $users;
}

function create_hotspot_broadcast_alerts(
  PDO $pdo,
  int $hotspotId,
  ?int $incidentId,
  string $title,
  string $message,
  string $severity = "HIGH"
): array {
  $targets = get_users_inside_hotspot($pdo, $hotspotId, 30);

  if (!$targets) {
    return [
      "created" => 0,
      "targets" => []
    ];
  }

  $stmt = $pdo->prepare("
    INSERT INTO notification_alerts
    (user_id, type, title, message, hotspot_id, incident_id, severity, is_read, created_at)
    VALUES (?, 'HOTSPOT_ALERT', ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())
  ");

  $created = 0;
  foreach ($targets as $t) {
    $stmt->execute([
      $t["user_id"],
      $title,
      $message,
      $hotspotId,
      $incidentId,
      $severity
    ]);
    $created++;
  }

  return [
    "created" => $created,
    "targets" => $targets
  ];
}

function recalc_hotspots_after_incident_save(PDO $pdo, int $incidentId): void {
  $stmt = $pdo->prepare("
    SELECT lat, lng
    FROM incident_reports
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$incidentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || $row["lat"] === null || $row["lng"] === null) {
    return;
  }

  $lat = (float)$row["lat"];
  $lng = (float)$row["lng"];

  hotspot_refresh_incident_link($pdo, $incidentId);
  hotspot_refresh_nearby_links($pdo, $lat, $lng, 500);
  hotspot_deactivate_orphan_hotspots($pdo);
}

function queue_incident_hotspot_alerts(PDO $pdo, int $incidentId): array {
  $stmt = $pdo->prepare("
    SELECT
      r.id,
      r.title,
      r.incident_type,
      r.barangay,
      r.city_municipality,
      r.hotspot_id,
      h.risk_level
    FROM incident_reports r
    LEFT JOIN crime_hotspots h ON h.id = r.hotspot_id
    WHERE r.id = ?
    LIMIT 1
  ");
  $stmt->execute([$incidentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row["hotspot_id"])) {
    return [
      "created" => 0,
      "targets" => []
    ];
  }

  $incidentLabel = trim((string)($row["incident_type"] ?: $row["title"] ?: "incident"));
  $place = trim((string)($row["barangay"] ?: $row["city_municipality"] ?: "your area"));
  $severity = strtoupper(trim((string)($row["risk_level"] ?? "HIGH")));
  if (!in_array($severity, ["LOW", "MEDIUM", "HIGH"], true)) {
    $severity = "HIGH";
  }

  $title = "Police Advisory";
  $message = "A {$incidentLabel} incident was blottered near {$place}. Please stay alert and avoid the area if possible.";

  return create_hotspot_broadcast_alerts(
    $pdo,
    (int)$row["hotspot_id"],
    $incidentId,
    $title,
    $message,
    $severity
  );
}