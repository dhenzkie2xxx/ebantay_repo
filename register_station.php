<?php
require_once __DIR__ . "/require_admin_account.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

$data = station_json_input();

$required = [
  "station_name",
  "station_type",
  "region",
  "city_municipality",
  "full_address",
  "lat",
  "lng",
  "contact_person"
];

$missing = station_require_fields($data, $required);
if (!empty($missing)) {
  auth_out(400, [
    "ok" => false,
    "message" => "Missing required fields.",
    "missing_fields" => $missing
  ]);
}

$stationType = station_clean($data["station_type"] ?? "");
if (!in_array($stationType, station_allowed_types(), true)) {
  auth_out(400, ["ok" => false, "message" => "Invalid station type."]);
}

$coordError = station_validate_coordinates($data["lat"] ?? null, $data["lng"] ?? null);
if ($coordError !== null) {
  auth_out(400, ["ok" => false, "message" => $coordError]);
}

$stationName = station_clean($data["station_name"] ?? "");
$region = station_clean($data["region"] ?? "");
$province = station_nullable_string($data["province"] ?? null);
$cityMunicipality = station_clean($data["city_municipality"] ?? "");
$barangay = station_nullable_string($data["barangay"] ?? null);
$sitio = station_nullable_string($data["sitio"] ?? null);
$streetAddress = station_nullable_string($data["street_address"] ?? null);
$fullAddress = station_clean($data["full_address"] ?? "");
$lat = (float)$data["lat"];
$lng = (float)$data["lng"];
$accuracyM = isset($data["accuracy_m"]) && $data["accuracy_m"] !== "" ? (int)$data["accuracy_m"] : null;

$contactPerson = station_clean($data["contact_person"] ?? "");
$contactPosition = station_nullable_string($data["contact_position"] ?? null);
$contactMobile = station_nullable_string($data["contact_mobile"] ?? null);
$contactLandline = station_nullable_string($data["contact_landline"] ?? null);
$contactEmail = station_nullable_string($data["contact_email"] ?? null);
$operatingHours = station_nullable_string($data["operating_hours"] ?? null);
$emergencyContact = station_nullable_string($data["emergency_contact"] ?? null);

/* operating_hours JSON validation */
if ($operatingHours !== null) {
  $decodedOperatingHours = json_decode($operatingHours, true);

  if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedOperatingHours)) {
    auth_out(400, [
      "ok" => false,
      "message" => "Invalid operating hours format."
    ]);
  }

  $allowedDays = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
    "Sunday"
  ];

  $is24_7 = !empty($decodedOperatingHours["is_24_7"]);
  $daysBlock = $decodedOperatingHours["days"] ?? $decodedOperatingHours;

  if (!is_array($daysBlock)) {
    auth_out(400, [
      "ok" => false,
      "message" => "Invalid operating hours day structure."
    ]);
  }

  foreach ($daysBlock as $day => $row) {
    if (!in_array($day, $allowedDays, true)) {
      if ($day === "is_24_7") {
        continue;
      }

      auth_out(400, [
        "ok" => false,
        "message" => "Invalid operating hours day: {$day}."
      ]);
    }

    if (!is_array($row)) {
      auth_out(400, [
        "ok" => false,
        "message" => "Invalid operating hours entry for {$day}."
      ]);
    }

    $enabled = (bool)($row["enabled"] ?? false);
    $open = (string)($row["open"] ?? "");
    $close = (string)($row["close"] ?? "");

    if (!$is24_7 && $enabled) {
      if (!preg_match('/^\d{2}:\d{2}$/', $open) || !preg_match('/^\d{2}:\d{2}$/', $close)) {
        auth_out(400, [
          "ok" => false,
          "message" => "Invalid open/close time format for {$day}."
        ]);
      }

      if ($open >= $close) {
        auth_out(400, [
          "ok" => false,
          "message" => "Opening time must be earlier than closing time for {$day}."
        ]);
      }
    }
  }
}

try {
  /*
  |--------------------------------------------------------------------------
  | Default contact email to logged-in admin email if empty
  |--------------------------------------------------------------------------
  */
  if (!$contactEmail) {
    $userStmt = $pdo->prepare("
      SELECT email
      FROM users
      WHERE id = ?
      LIMIT 1
    ");
    $userStmt->execute([$AUTH_USER["id"]]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    $contactEmail = $userRow["email"] ?? null;
  }

  if ($contactEmail !== null && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    auth_out(400, ["ok" => false, "message" => "Invalid contact email format."]);
  }

  $pdo->beginTransaction();

  $existingStmt = $pdo->prepare("
    SELECT id, verification_status
    FROM police_stations
    WHERE created_by = ?
    LIMIT 1
  ");
  $existingStmt->execute([$AUTH_USER["id"]]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
    $stationId = (int)$existing["id"];
    $status = $existing["verification_status"];

    if (!in_array($status, station_can_edit_statuses(), true)) {
      $pdo->rollBack();
      auth_out(403, [
        "ok" => false,
        "message" => "This station can no longer be edited in its current status.",
        "verification_status" => $status
      ]);
    }

    $dupStmt = $pdo->prepare("
      SELECT id
      FROM police_stations
      WHERE station_name = ?
        AND city_municipality = ?
        AND id <> ?
      LIMIT 1
    ");
    $dupStmt->execute([$stationName, $cityMunicipality, $stationId]);
    if ($dupStmt->fetch()) {
      $pdo->rollBack();
      auth_out(409, [
        "ok" => false,
        "message" => "Another station with the same name and city already exists."
      ]);
    }

    $upd = $pdo->prepare("
      UPDATE police_stations
      SET
        station_type = ?,
        station_name = ?,
        region = ?,
        province = ?,
        city_municipality = ?,
        barangay = ?,
        sitio = ?,
        street_address = ?,
        full_address = ?,
        lat = ?,
        lng = ?,
        accuracy_m = ?,
        contact_person = ?,
        contact_position = ?,
        contact_mobile = ?,
        contact_landline = ?,
        contact_email = ?,
        operating_hours = ?,
        emergency_contact = ?,
        rejection_reason = CASE
          WHEN verification_status IN ('rejected','resubmission_required') THEN NULL
          ELSE rejection_reason
        END,
        verification_status = CASE
          WHEN verification_status IN ('rejected','resubmission_required') THEN 'draft'
          ELSE verification_status
        END
      WHERE id = ?
    ");
    $upd->execute([
      $stationType,
      $stationName,
      $region,
      $province,
      $cityMunicipality,
      $barangay,
      $sitio,
      $streetAddress,
      $fullAddress,
      $lat,
      $lng,
      $accuracyM,
      $contactPerson,
      $contactPosition,
      $contactMobile,
      $contactLandline,
      $contactEmail,
      $operatingHours,
      $emergencyContact,
      $stationId
    ]);

    $pdo->commit();

    auth_out(200, [
      "ok" => true,
      "message" => "Station updated successfully.",
      "station_id" => $stationId
    ]);
  }

  $dupStmt = $pdo->prepare("
    SELECT id
    FROM police_stations
    WHERE station_name = ?
      AND city_municipality = ?
    LIMIT 1
  ");
  $dupStmt->execute([$stationName, $cityMunicipality]);
  if ($dupStmt->fetch()) {
    $pdo->rollBack();
    auth_out(409, [
      "ok" => false,
      "message" => "A station with the same name and city already exists."
    ]);
  }

  $stationCode = station_generate_code($cityMunicipality, $stationName);

  $ins = $pdo->prepare("
    INSERT INTO police_stations (
      station_code,
      station_name,
      station_type,
      region,
      province,
      city_municipality,
      barangay,
      sitio,
      street_address,
      full_address,
      lat,
      lng,
      accuracy_m,
      contact_person,
      contact_position,
      contact_mobile,
      contact_landline,
      contact_email,
      operating_hours,
      emergency_contact,
      verification_status,
      is_active,
      created_by
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 1, ?
    )
  ");
  $ins->execute([
    $stationCode,
    $stationName,
    $stationType,
    $region,
    $province,
    $cityMunicipality,
    $barangay,
    $sitio,
    $streetAddress,
    $fullAddress,
    $lat,
    $lng,
    $accuracyM,
    $contactPerson,
    $contactPosition,
    $contactMobile,
    $contactLandline,
    $contactEmail,
    $operatingHours,
    $emergencyContact,
    $AUTH_USER["id"]
  ]);

  $stationId = (int)$pdo->lastInsertId();

  $linkUser = $pdo->prepare("
    UPDATE users
    SET station_id = ?, account_status = 'pending', valid = 'unvalid'
    WHERE id = ?
  ");
  $linkUser->execute([$stationId, $AUTH_USER["id"]]);

  $pdo->commit();

  auth_out(200, [
    "ok" => true,
    "message" => "Station registered successfully.",
    "station_id" => $stationId
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}