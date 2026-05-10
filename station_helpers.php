<?php

function station_json_input(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function station_clean(?string $value): string {
  return trim((string)$value);
}

function station_nullable_string($value): ?string {
  $v = trim((string)$value);
  return $v === "" ? null : $v;
}

function station_require_fields(array $data, array $required): array {
  $missing = [];

  foreach ($required as $field) {
    $value = isset($data[$field]) ? trim((string)$data[$field]) : "";
    if ($value === "") {
      $missing[] = $field;
    }
  }

  return $missing;
}

function station_validate_coordinates($lat, $lng): ?string {
  if ($lat === null || $lng === null || $lat === "" || $lng === "") {
    return "Latitude and longitude are required.";
  }

  if (!is_numeric($lat) || !is_numeric($lng)) {
    return "Latitude and longitude must be numeric.";
  }

  $lat = (float)$lat;
  $lng = (float)$lng;

  if ($lat < -90 || $lat > 90) {
    return "Latitude is out of range.";
  }

  if ($lng < -180 || $lng > 180) {
    return "Longitude is out of range.";
  }

  return null;
}

function station_allowed_types(): array {
  return [
    "regional_office",
    "provincial_office",
    "city_station",
    "municipal_station",
    "barangay_outpost",
    "other"
  ];
}

function station_default_required_document_types(): array {
  return [
    "pnp_certification",
    "lgu_endorsement",
    "location_proof",
    "station_photo",
    "commander_designation"
  ];
}

function station_required_document_types(?PDO $pdo = null): array {
  if (!$pdo) {
    return station_default_required_document_types();
  }

  try {
    $stmt = $pdo->query("
      SELECT requirement_code
      FROM station_document_requirements
      WHERE active = 1
        AND is_required = 1
      ORDER BY is_system DESC, id ASC
    ");

    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $code = trim((string)($row["requirement_code"] ?? ""));
      if ($code !== "") {
        $items[] = $code;
      }
    }

    $items = array_values(array_unique(array_merge(
      station_default_required_document_types(),
      $items
    )));

    return $items;
  } catch (Throwable $e) {
    return station_default_required_document_types();
  }
}

function station_optional_document_types(?PDO $pdo = null): array {
  $base = [
    "id_card",
    "official_letter",
    "other"
  ];

  if (!$pdo) {
    return $base;
  }

  try {
    $stmt = $pdo->query("
      SELECT requirement_code
      FROM station_document_requirements
      WHERE active = 1
        AND is_required = 0
      ORDER BY is_system DESC, id ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $code = trim((string)($row["requirement_code"] ?? ""));
      if ($code !== "") {
        $base[] = $code;
      }
    }

    return array_values(array_unique($base));
  } catch (Throwable $e) {
    return $base;
  }
}

function station_all_document_types(?PDO $pdo = null): array {
  return array_values(array_unique(array_merge(
    station_required_document_types($pdo),
    station_optional_document_types($pdo)
  )));
}

function station_document_requirement_labels(?PDO $pdo = null): array {
  $labels = [
    "pnp_certification" => "PNP Certification / Authorization",
    "lgu_endorsement" => "LGU Endorsement / Resolution",
    "location_proof" => "Proof of Station Location",
    "station_photo" => "Station Photo with Signage",
    "commander_designation" => "Commander / Officer Designation",
    "id_card" => "Official ID Card",
    "official_letter" => "Official Letter",
    "other" => "Other Supporting Document"
  ];

  if (!$pdo) {
    return $labels;
  }

  try {
    $stmt = $pdo->query("
      SELECT requirement_code, requirement_name
      FROM station_document_requirements
      WHERE active = 1
      ORDER BY is_system DESC, id ASC
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $code = trim((string)($row["requirement_code"] ?? ""));
      $name = trim((string)($row["requirement_name"] ?? ""));

      if ($code !== "" && $name !== "") {
        $labels[$code] = $name;
      }
    }
  } catch (Throwable $e) {
    // keep default labels
  }

  return $labels;
}

function station_can_edit_statuses(): array {
  return [
    "draft",
    "rejected",
    "resubmission_required"
  ];
}

function station_can_submit_statuses(): array {
  return [
    "draft",
    "rejected",
    "resubmission_required",
    "pending"
  ];
}

function station_generate_code(string $city, string $name): string {
  $cityPart = strtoupper(preg_replace('/[^A-Z0-9]+/', '', substr($city, 0, 6)));
  $namePart = strtoupper(preg_replace('/[^A-Z0-9]+/', '', substr($name, 0, 6)));
  $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
  return trim($cityPart . "-" . $namePart . "-" . $rand, "-");
}