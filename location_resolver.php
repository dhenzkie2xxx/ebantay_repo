<?php

function location_normalize_text($value): string {
  return strtolower(trim((string)($value ?? "")));
}

function location_nullable_text($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function resolve_province(PDO $pdo, string $input): ?string {
  $norm = location_normalize_text($input);
  if ($norm === "") return null;

  $stmt = $pdo->prepare("
    SELECT p.canonical_name
    FROM location_provinces p
    LEFT JOIN location_province_aliases a ON a.province_id = p.id
    WHERE LOWER(p.canonical_name) = ?
       OR LOWER(a.alias_name) = ?
    LIMIT 1
  ");
  $stmt->execute([$norm, $norm]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function resolve_city(PDO $pdo, string $provinceCanonical, string $input): ?string {
  $provinceCanonical = trim((string)$provinceCanonical);
  $norm = location_normalize_text($input);

  if ($provinceCanonical === "" || $norm === "") return null;

  $stmt = $pdo->prepare("
    SELECT c.canonical_name
    FROM location_cities c
    JOIN location_provinces p ON p.id = c.province_id
    LEFT JOIN location_city_aliases a ON a.city_id = c.id
    WHERE LOWER(p.canonical_name) = LOWER(?)
      AND (
        LOWER(c.canonical_name) = ?
        OR LOWER(a.alias_name) = ?
      )
    LIMIT 1
  ");
  $stmt->execute([$provinceCanonical, $norm, $norm]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function resolve_region_from_province(PDO $pdo, string $provinceCanonical): ?string {
  $provinceCanonical = trim((string)$provinceCanonical);
  if ($provinceCanonical === "") return null;

  $stmt = $pdo->prepare("
    SELECT r.canonical_name
    FROM location_provinces p
    JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(p.canonical_name) = LOWER(?)
    LIMIT 1
  ");
  $stmt->execute([$provinceCanonical]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function canonicalize_scope(PDO $pdo, ?string $region, ?string $province, ?string $cityMunicipality): array {
  $province = location_nullable_text($province);
  $cityMunicipality = location_nullable_text($cityMunicipality);
  $region = location_nullable_text($region);

  if (!$province) {
    return [
      "ok" => false,
      "message" => "Invalid province name."
    ];
  }

  $provinceCanonical = resolve_province($pdo, $province);
  if (!$provinceCanonical) {
    return [
      "ok" => false,
      "message" => "Invalid province name. Please use the official province name."
    ];
  }

  if (!$cityMunicipality) {
    return [
      "ok" => false,
      "message" => "Invalid city/municipality for selected province."
    ];
  }

  $cityCanonical = resolve_city($pdo, $provinceCanonical, $cityMunicipality);
  if (!$cityCanonical) {
    return [
      "ok" => false,
      "message" => "Invalid city/municipality for the selected province. Please use the official city/municipality name."
    ];
  }

  $regionCanonical = resolve_region_from_province($pdo, $provinceCanonical);
  if (!$regionCanonical) {
    $regionCanonical = $region;
  }

  return [
    "ok" => true,
    "region" => $regionCanonical,
    "province" => $provinceCanonical,
    "city_municipality" => $cityCanonical
  ];
}