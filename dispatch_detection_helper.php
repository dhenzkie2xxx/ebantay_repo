<?php

function dispatch_distance_meters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;

  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a =
    sin($dLat / 2) * sin($dLat / 2) +
    cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
    sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

function dispatch_find_nearest_available_police(
  PDO $pdo,
  int $stationId,
  float $lat,
  float $lng
): ?array {
  $stmt = $pdo->prepare("
    SELECT
      u.id,
      u.firstname,
      u.lastname,
      u.username,
      u.station_id,
      u.duty_status,
      u.last_seen_at,

      latest.lat,
      latest.lng,
      latest.accuracy_m,
      latest.created_at AS location_updated_at

    FROM users u

    INNER JOIN (
      SELECT ul1.*
      FROM user_locations ul1
      INNER JOIN (
        SELECT user_id, MAX(created_at) AS max_created_at
        FROM user_locations
        GROUP BY user_id
      ) ul2
        ON ul2.user_id = ul1.user_id
       AND ul2.max_created_at = ul1.created_at
    ) latest
      ON latest.user_id = u.id

    WHERE u.role = 'police_on_field'
      AND u.station_id = ?
      AND u.valid = 'valid'
      AND u.account_status = 'active'
      AND u.is_email_verified = 1
      AND u.account_flag_status <> 'suspended'
      AND u.duty_status = 'available'
      AND latest.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
  ");

  $stmt->execute([$stationId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $nearest = null;
  $nearestDistance = null;

  foreach ($rows as $r) {
    if (!is_numeric($r["lat"]) || !is_numeric($r["lng"])) {
      continue;
    }

    $distance = dispatch_distance_meters(
      $lat,
      $lng,
      (float)$r["lat"],
      (float)$r["lng"]
    );

    if ($nearestDistance === null || $distance < $nearestDistance) {
      $nearestDistance = $distance;
      $nearest = $r;
      $nearest["distance_m"] = (int)round($distance);
    }
  }

  return $nearest;
}

function dispatch_get_station_admin(PDO $pdo, int $stationId): ?array {
  $stmt = $pdo->prepare("
    SELECT id, firstname, lastname
    FROM users
    WHERE role = 'admin'
      AND station_id = ?
      AND valid = 'valid'
      AND account_status = 'active'
    LIMIT 1
  ");

  $stmt->execute([$stationId]);
  $admin = $stmt->fetch(PDO::FETCH_ASSOC);

  return $admin ?: null;
}

function dispatch_create_detected_assignment(
  PDO $pdo,
  string $sourceType,
  int $sourceId,
  int $stationId,
  float $lat,
  float $lng
): array {
  if (!in_array($sourceType, ["incident", "panic"], true)) {
    return [
      "ok" => false,
      "message" => "Invalid source type."
    ];
  }

  $nearestPolice = dispatch_find_nearest_available_police(
    $pdo,
    $stationId,
    $lat,
    $lng
  );

  $stationAdmin = dispatch_get_station_admin($pdo, $stationId);

  if ($stationAdmin) {
    $adminTitle = $sourceType === "incident"
      ? "New Incident Report Received"
      : "New Panic Request Received";

    $adminMessage = $sourceType === "incident"
      ? "A new incident report was received. Review and send Go Signal if needed."
      : "A new panic request was received. Review and send Go Signal if needed.";

    $adminNotif = $pdo->prepare("
      INSERT INTO notification_alerts (
        user_id,
        type,
        title,
        message,
        incident_id,
        severity,
        is_read
      )
      VALUES (?, ?, ?, ?, ?, 'HIGH', 0)
    ");

    $adminNotif->execute([
      (int)$stationAdmin["id"],
      $sourceType === "incident" ? "INCIDENT_RECEIVED" : "PANIC_RECEIVED",
      $adminTitle,
      $adminMessage,
      $sourceType === "incident" ? $sourceId : null
    ]);
  }

  if (!$nearestPolice) {
    return [
      "ok" => true,
      "message" => "No available Police on Field detected.",
      "assignment_id" => null,
      "nearest_police" => null
    ];
  }

  $existing = $pdo->prepare("
    SELECT id
    FROM responder_assignments
    WHERE source_type = ?
      AND source_id = ?
      AND assigned_user_id = ?
      AND status <> 'cancelled'
    LIMIT 1
  ");

  $existing->execute([
    $sourceType,
    $sourceId,
    (int)$nearestPolice["id"]
  ]);

  $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

  if ($existingRow) {
    $assignmentId = (int)$existingRow["id"];
  } else {
    $insert = $pdo->prepare("
      INSERT INTO responder_assignments (
        source_type,
        source_id,
        assigned_user_id,
        assigned_station_id,
        assignment_role,
        status,
        authorization_status,
        detected_distance_m,
        notes
      )
      VALUES (?, ?, ?, ?, 'PRIMARY', 'new', 'detected', ?, ?)
    ");

    $insert->execute([
      $sourceType,
      $sourceId,
      (int)$nearestPolice["id"],
      $stationId,
      (int)$nearestPolice["distance_m"],
      "Nearest available Police on Field detected. Waiting for Go Signal or confirmation approval."
    ]);

    $assignmentId = (int)$pdo->lastInsertId();
  }

  $policeNotif = $pdo->prepare("
    INSERT INTO notification_alerts (
      user_id,
      type,
      title,
      message,
      incident_id,
      severity,
      is_read
    )
    VALUES (?, 'NEAREST_UNIT_DETECTED', ?, ?, ?, 'HIGH', 0)
  ");

  $policeNotif->execute([
    (int)$nearestPolice["id"],
    $sourceType === "incident"
      ? "Incident Report Nearby"
      : "Panic Request Nearby",
    "You are the detected nearest Police on Field. Please wait for Go Signal or request confirmation to proceed.",
    $sourceType === "incident" ? $sourceId : null
  ]);

  return [
    "ok" => true,
    "message" => "Nearest Police on Field detected.",
    "assignment_id" => $assignmentId,
    "nearest_police" => [
      "id" => (int)$nearestPolice["id"],
      "firstname" => $nearestPolice["firstname"],
      "lastname" => $nearestPolice["lastname"],
      "username" => $nearestPolice["username"],
      "distance_m" => (int)$nearestPolice["distance_m"],
      "lat" => (float)$nearestPolice["lat"],
      "lng" => (float)$nearestPolice["lng"],
      "location_updated_at" => $nearestPolice["location_updated_at"]
    ]
  ];
}