<?php
require_once __DIR__ . "/db.php";

header("Content-Type: text/plain; charset=UTF-8");

$secret = $_GET["key"] ?? "";
if ($secret !== "IMPORT_2026_SECRET") {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

set_time_limit(0);

function fetch_json_or_fail(string $url): array {
  $opts = [
    "http" => [
      "method" => "GET",
      "header" =>
        "User-Agent: eBantay/1.0\r\n" .
        "Accept: application/json\r\n",
      "timeout" => 60
    ]
  ];

  $context = stream_context_create($opts);
  $raw = @file_get_contents($url, false, $context);
  if ($raw === false) {
    throw new RuntimeException("Failed to fetch: {$url}");
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    throw new RuntimeException("Invalid JSON from: {$url}");
  }

  if (isset($json["data"]) && is_array($json["data"])) {
    return $json["data"];
  }

  return $json;
}

function norm_alias(string $value): string {
  return strtolower(trim($value));
}

function ensure_region(PDO $pdo, string $canonical): int {
  $find = $pdo->prepare("
    SELECT id
    FROM location_regions
    WHERE LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");
  $find->execute([$canonical]);
  $id = $find->fetchColumn();

  if ($id) return (int)$id;

  $ins = $pdo->prepare("
    INSERT INTO location_regions (canonical_name)
    VALUES (?)
  ");
  $ins->execute([$canonical]);

  return (int)$pdo->lastInsertId();
}

function ensure_province(PDO $pdo, int $regionId, string $canonical): int {
  $find = $pdo->prepare("
    SELECT id
    FROM location_provinces
    WHERE LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");
  $find->execute([$canonical]);
  $id = $find->fetchColumn();

  if ($id) return (int)$id;

  $ins = $pdo->prepare("
    INSERT INTO location_provinces (region_id, canonical_name)
    VALUES (?, ?)
  ");
  $ins->execute([$regionId, $canonical]);

  return (int)$pdo->lastInsertId();
}

function ensure_city(PDO $pdo, int $provinceId, string $canonical): int {
  $find = $pdo->prepare("
    SELECT id
    FROM location_cities
    WHERE province_id = ? AND LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");
  $find->execute([$provinceId, $canonical]);
  $id = $find->fetchColumn();

  if ($id) return (int)$id;

  $ins = $pdo->prepare("
    INSERT INTO location_cities (province_id, canonical_name)
    VALUES (?, ?)
  ");
  $ins->execute([$provinceId, $canonical]);

  return (int)$pdo->lastInsertId();
}

function ensure_province_alias(PDO $pdo, int $provinceId, string $alias): void {
  $find = $pdo->prepare("
    SELECT id
    FROM location_province_aliases
    WHERE province_id = ? AND LOWER(alias_name) = LOWER(?)
    LIMIT 1
  ");
  $find->execute([$provinceId, $alias]);
  if ($find->fetchColumn()) return;

  $ins = $pdo->prepare("
    INSERT INTO location_province_aliases (province_id, alias_name)
    VALUES (?, ?)
  ");
  $ins->execute([$provinceId, $alias]);
}

function ensure_city_alias(PDO $pdo, int $cityId, string $alias): void {
  $find = $pdo->prepare("
    SELECT id
    FROM location_city_aliases
    WHERE city_id = ? AND LOWER(alias_name) = LOWER(?)
    LIMIT 1
  ");
  $find->execute([$cityId, $alias]);
  if ($find->fetchColumn()) return;

  $ins = $pdo->prepare("
    INSERT INTO location_city_aliases (city_id, alias_name)
    VALUES (?, ?)
  ");
  $ins->execute([$cityId, $alias]);
}

function add_province_aliases(PDO $pdo, int $provinceId, string $canonical): void {
  $aliases = [
    norm_alias($canonical)
  ];

  foreach (array_values(array_unique($aliases)) as $alias) {
    if ($alias !== "") {
      ensure_province_alias($pdo, $provinceId, $alias);
    }
  }
}

function add_city_aliases(PDO $pdo, int $cityId, string $canonical): void {
  $base = norm_alias($canonical);
  $aliases = [$base];

  // Case 1: "Tangub City"
  if (str_ends_with($base, " city")) {
    $withoutCity = trim(substr($base, 0, -5));
    if ($withoutCity !== "") {
      $aliases[] = $withoutCity;
      $aliases[] = "city of " . $withoutCity;
    }
  }

  // Case 2: "City of Tangub"
  if (str_starts_with($base, "city of ")) {
    $withoutPrefix = trim(substr($base, 8));
    if ($withoutPrefix !== "") {
      $aliases[] = $withoutPrefix;
      $aliases[] = $withoutPrefix . " city";
    }
  }

  foreach (array_values(array_unique($aliases)) as $alias) {
    if ($alias !== "") {
      ensure_city_alias($pdo, $cityId, $alias);
    }
  }
}

try {
  echo "Starting PSGC import...\n";

  $pdo->beginTransaction();

  $regionInserted = 0;
  $provinceInserted = 0;
  $cityInserted = 0;

  $regions = fetch_json_or_fail("https://psgc.cloud/api/v2/regions");
  foreach ($regions as $region) {
    $name = trim((string)($region["name"] ?? ""));
    if ($name === "") continue;

    $before = $pdo->prepare("
      SELECT id FROM location_regions
      WHERE LOWER(canonical_name)=LOWER(?)
      LIMIT 1
    ");
    $before->execute([$name]);
    $exists = $before->fetchColumn();

    ensure_region($pdo, $name);
    if (!$exists) $regionInserted++;

    echo "Region: {$name}\n";
  }

  $provinces = fetch_json_or_fail("https://psgc.cloud/api/v2/provinces");
  foreach ($provinces as $province) {
    $provinceName = trim((string)($province["name"] ?? ""));
    $regionName = trim((string)($province["region"] ?? ""));

    if ($provinceName === "" || $regionName === "") {
      continue;
    }

    $regionId = ensure_region($pdo, $regionName);

    $before = $pdo->prepare("
      SELECT id FROM location_provinces
      WHERE LOWER(canonical_name)=LOWER(?)
      LIMIT 1
    ");
    $before->execute([$provinceName]);
    $exists = $before->fetchColumn();

    $provinceId = ensure_province($pdo, $regionId, $provinceName);
    if (!$exists) $provinceInserted++;

    add_province_aliases($pdo, $provinceId, $provinceName);
    echo "Province: {$provinceName} ({$regionName})\n";
  }

  $localities = fetch_json_or_fail("https://psgc.cloud/api/v2/cities-municipalities");
  foreach ($localities as $loc) {
    $name = trim((string)($loc["name"] ?? ""));
    $type = strtolower(trim((string)($loc["type"] ?? "")));
    $provinceName = trim((string)($loc["province"] ?? ""));
    $regionName = trim((string)($loc["region"] ?? ""));

    if ($name === "" || $provinceName === "" || $regionName === "") {
      continue;
    }

    $regionId = ensure_region($pdo, $regionName);
    $provinceId = ensure_province($pdo, $regionId, $provinceName);
    
    $canonical = $name;
    $lowerCanonical = strtolower($canonical);

    if ($type === "city") {
    if (str_starts_with($lowerCanonical, "city of ")) {
        $bare = trim(substr($canonical, 8));
        if ($bare !== "") {
        $canonical = $bare . " City";
        }
    } elseif (!str_contains($lowerCanonical, "city")) {
        $canonical .= " City";
    }
    }

    $before = $pdo->prepare("
      SELECT id FROM location_cities
      WHERE province_id = ? AND LOWER(canonical_name)=LOWER(?)
      LIMIT 1
    ");
    $before->execute([$provinceId, $canonical]);
    $exists = $before->fetchColumn();

    $cityId = ensure_city($pdo, $provinceId, $canonical);
    if (!$exists) $cityInserted++;

    add_city_aliases($pdo, $cityId, $canonical);
  }

  $pdo->commit();

  echo "\nImport completed successfully.\n";
  echo "New regions inserted: {$regionInserted}\n";
  echo "New provinces inserted: {$provinceInserted}\n";
  echo "New cities/municipalities inserted: {$cityInserted}\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  http_response_code(500);
  echo "Import failed: " . $e->getMessage() . "\n";
}