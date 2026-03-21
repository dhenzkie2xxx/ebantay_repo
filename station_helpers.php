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
    if ($value === "") $missing[] = $field;
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

function station_required_document_types(): array {
  return [
    "proof_of_assignment",
    "id_card",
    "office_photo"
  ];
}

function station_can_edit_statuses(): array {
  return [
    "draft",
    "pending",
    "rejected",
    "resubmission_required"
  ];
}

function station_generate_code(string $city, string $name): string {
  $cityPart = strtoupper(preg_replace('/[^A-Z0-9]+/', '', substr($city, 0, 6)));
  $namePart = strtoupper(preg_replace('/[^A-Z0-9]+/', '', substr($name, 0, 6)));
  $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
  return trim($cityPart . "-" . $namePart . "-" . $rand, "-");
}