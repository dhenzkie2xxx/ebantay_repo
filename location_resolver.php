<?php

function location_normalize_text($value): string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return strtolower($value);
}

function location_nullable_text($value): ?string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value === "" ? null : $value;
}

function location_region_alias_to_canonical(?string $input): ?string {
  $norm = location_normalize_text($input);
  if ($norm === "") return null;

  $aliases = [
    "region x" => "Northern Mindanao",
    "region 10" => "Northern Mindanao",
    "region ten" => "Northern Mindanao",
    "x" => "Northern Mindanao",
    "10" => "Northern Mindanao",
    "northern mindanao" => "Northern Mindanao",

    "region ix" => "Zamboanga Peninsula",
    "region 9" => "Zamboanga Peninsula",
    "region nine" => "Zamboanga Peninsula",
    "ix" => "Zamboanga Peninsula",
    "9" => "Zamboanga Peninsula",
    "zamboanga peninsula" => "Zamboanga Peninsula",

    "region xi" => "Davao Region",
    "region 11" => "Davao Region",
    "region eleven" => "Davao Region",
    "xi" => "Davao Region",
    "11" => "Davao Region",
    "davao region" => "Davao Region",

    "region vii" => "Central Visayas",
    "region 7" => "Central Visayas",
    "region seven" => "Central Visayas",
    "vii" => "Central Visayas",
    "7" => "Central Visayas",
    "central visayas" => "Central Visayas",

    "ncr" => "National Capital Region",
    "metro manila" => "National Capital Region",
    "national capital region" => "National Capital Region"
  ];

  return $aliases[$norm] ?? null;
}

function resolve_region(PDO $pdo, ?string $input): ?string {
  $norm = location_normalize_text($input);
  if ($norm === "") return null;

  $alias = location_region_alias_to_canonical($input);
  if ($alias) return $alias;

  $stmt = $pdo->prepare("
    SELECT canonical_name
    FROM location_regions
    WHERE LOWER(TRIM(canonical_name)) = ?
    LIMIT 1
  ");
  $stmt->execute([$norm]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function resolve_province(PDO $pdo, string $input): ?string {
  $norm = location_normalize_text($input);
  if ($norm === "") return null;

  $stmt = $pdo->prepare("
    SELECT p.canonical_name
    FROM location_provinces p
    LEFT JOIN location_province_aliases a ON a.province_id = p.id
    WHERE LOWER(TRIM(p.canonical_name)) = ?
       OR LOWER(TRIM(a.alias_name)) = ?
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
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND (
        LOWER(TRIM(c.canonical_name)) = ?
        OR LOWER(TRIM(a.alias_name)) = ?
      )
    LIMIT 1
  ");
  $stmt->execute([$provinceCanonical, $norm, $norm]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function resolve_scope_from_city(PDO $pdo, ?string $cityMunicipality): array {
  $cityMunicipality = location_nullable_text($cityMunicipality);

  if (!$cityMunicipality) {
    return [
      "ok" => false,
      "region" => null,
      "province" => null,
      "city_municipality" => null,
      "message" => "Invalid city/municipality name."
    ];
  }

  $sql = "
    SELECT
      c.canonical_name AS city_municipality,
      p.canonical_name AS province,
      r.canonical_name AS region
    FROM location_cities c
    INNER JOIN location_provinces p ON p.id = c.province_id
    INNER JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))

    UNION

    SELECT
      c.canonical_name AS city_municipality,
      p.canonical_name AS province,
      r.canonical_name AS region
    FROM location_city_aliases a
    INNER JOIN location_cities c ON c.id = a.city_id
    INNER JOIN location_provinces p ON p.id = c.province_id
    INNER JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(TRIM(a.alias_name)) = LOWER(TRIM(?))

    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$cityMunicipality, $cityMunicipality]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    return [
      "ok" => false,
      "region" => null,
      "province" => null,
      "city_municipality" => $cityMunicipality,
      "message" => "City/municipality was not found in the location reference table."
    ];
  }

  return [
    "ok" => true,
    "region" => location_nullable_text($row["region"] ?? null),
    "province" => location_nullable_text($row["province"] ?? null),
    "city_municipality" => location_nullable_text($row["city_municipality"] ?? $cityMunicipality),
  ];
}

function resolve_region_from_province(PDO $pdo, string $provinceCanonical): ?string {
  $provinceCanonical = trim((string)$provinceCanonical);
  if ($provinceCanonical === "") return null;

  $stmt = $pdo->prepare("
    SELECT r.canonical_name
    FROM location_provinces p
    JOIN location_regions r ON r.id = p.region_id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
    LIMIT 1
  ");
  $stmt->execute([$provinceCanonical]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function resolve_barangay(PDO $pdo, string $provinceCanonical, string $cityCanonical, string $input): ?string {
  $provinceCanonical = trim((string)$provinceCanonical);
  $cityCanonical = trim((string)$cityCanonical);
  $norm = location_normalize_text($input);

  if ($provinceCanonical === "" || $cityCanonical === "" || $norm === "") {
    return null;
  }

  $stmt = $pdo->prepare("
    SELECT b.canonical_name
    FROM location_barangays b
    JOIN location_cities c ON c.id = b.city_id
    JOIN location_provinces p ON p.id = c.province_id
    LEFT JOIN location_barangay_aliases a ON a.barangay_id = b.id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
      AND (
        LOWER(TRIM(b.canonical_name)) = ?
        OR LOWER(TRIM(a.alias_name)) = ?
      )
    LIMIT 1
  ");

  $stmt->execute([
    $provinceCanonical,
    $cityCanonical,
    $norm,
    $norm
  ]);

  $value = $stmt->fetchColumn();
  return $value !== false ? (string)$value : null;
}

function list_barangays_by_city(PDO $pdo, string $provinceCanonical, string $cityCanonical): array {
  $stmt = $pdo->prepare("
    SELECT b.canonical_name
    FROM location_barangays b
    JOIN location_cities c ON c.id = b.city_id
    JOIN location_provinces p ON p.id = c.province_id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
    ORDER BY b.canonical_name ASC
  ");

  $stmt->execute([$provinceCanonical, $cityCanonical]);

  return array_map(
    fn($r) => $r["canonical_name"],
    $stmt->fetchAll(PDO::FETCH_ASSOC)
  );
}

function canonicalize_scope(PDO $pdo, ?string $region, ?string $province, ?string $cityMunicipality): array {
  $province = location_nullable_text($province);
  $cityMunicipality = location_nullable_text($cityMunicipality);
  $region = location_nullable_text($region);

  $regionCanonical = resolve_region($pdo, $region) ?: $region;

  if (!$province && $cityMunicipality) {
    $fromCity = resolve_scope_from_city($pdo, $cityMunicipality);

    if (!empty($fromCity["ok"])) {
      return [
        "ok" => true,
        "region" => $fromCity["region"],
        "province" => $fromCity["province"],
        "city_municipality" => $fromCity["city_municipality"]
      ];
    }
  }

  if (!$province) {
    return [
      "ok" => false,
      "message" => "Invalid province name."
    ];
  }

  $provinceCanonical = resolve_province($pdo, $province);

  if (!$provinceCanonical && $cityMunicipality) {
    $fromCity = resolve_scope_from_city($pdo, $cityMunicipality);

    if (!empty($fromCity["ok"])) {
      $provinceCanonical = $fromCity["province"];
      $cityMunicipality = $fromCity["city_municipality"];
      $regionCanonical = $fromCity["region"];
    }
  }

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
    $fromCity = resolve_scope_from_city($pdo, $cityMunicipality);

    if (!empty($fromCity["ok"]) && strtolower($fromCity["province"]) === strtolower($provinceCanonical)) {
      $cityCanonical = $fromCity["city_municipality"];
    }
  }

  if (!$cityCanonical) {
    return [
      "ok" => false,
      "message" => "Invalid city/municipality for the selected province. Please use the official city/municipality name."
    ];
  }

  $regionFromProvince = resolve_region_from_province($pdo, $provinceCanonical);
  if ($regionFromProvince) {
    $regionCanonical = $regionFromProvince;
  }

  return [
    "ok" => true,
    "region" => $regionCanonical,
    "province" => $provinceCanonical,
    "city_municipality" => $cityCanonical
  ];
}