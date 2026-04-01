<?php
require_once __DIR__ . "/db.php";

header("Content-Type: text/plain; charset=UTF-8");

// protect this endpoint
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

  return $json;
}

function normalize_alias(string $value): string {
  return strtolower(trim($value));
}

function add_city_aliases(PDO $pdo, int $cityId, string $canonical): void {
  $aliases = [];
  $base = normalize_alias($canonical);

  $aliases[] = $base;

  if (str_ends_with($base, " city")) {
    $withoutCity = trim(substr($base, 0, -5));
    if ($withoutCity !== "") {
      $aliases[] = $withoutCity;
      $aliases[] = "city of " . $withoutCity;
    }
  } else {
    $aliases[] = $base;
  }

  $aliases = array_values(array_unique(array_filter($aliases)));

  $stmt = $pdo->prepare("
    INSERT INTO location_city_aliases (city_id, alias_name)
    VALUES (?, ?)
  ");

  foreach ($aliases as $alias) {
    $check = $pdo->prepare("
      SELECT id
      FROM location_city_aliases
      WHERE city_id = ? AND LOWER(alias_name) = LOWER(?)
      LIMIT 1
    ");
    $check->execute([$cityId, $alias]);
    if (!$check->fetchColumn()) {
      $stmt->execute([$cityId, $alias]);
    }
  }
}

function add_province_aliases(PDO $pdo, int $provinceId, string $canonical): void {
  $aliases = [normalize_alias($canonical)];
  $aliases = array_values(array_unique($aliases));

  $stmt = $pdo->prepare("
    INSERT INTO location_province_aliases (province_id, alias_name)
    VALUES (?, ?)
  ");

  foreach ($aliases as $alias) {
    $check = $pdo->prepare("
      SELECT id
      FROM location_province_aliases
      WHERE province_id = ? AND LOWER(alias_name) = LOWER(?)
      LIMIT 1
    ");
    $check->execute([$provinceId, $alias]);
    if (!$check->fetchColumn()) {
      $stmt->execute([$provinceId, $alias]);
    }
  }
}

try {
  echo "Starting PSGC import...\n";

  $pdo->beginTransaction();

  // 1) Regions
  $regions = fetch_json_or_fail("https://psgc.cloud/api/regions");
  $insertRegion = $pdo->prepare("
    INSERT INTO location_regions (canonical_name)
    VALUES (?)
  ");
  $findRegion = $pdo->prepare("
    SELECT id FROM location_regions
    WHERE LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");

  $insertProvince = $pdo->prepare("
    INSERT INTO location_provinces (region_id, canonical_name)
    VALUES (?, ?)
  ");
  $findProvince = $pdo->prepare("
    SELECT id FROM location_provinces
    WHERE region_id = ? AND LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");

  $insertCity = $pdo->prepare("
    INSERT INTO location_cities (province_id, canonical_name)
    VALUES (?, ?)
  ");
  $findCity = $pdo->prepare("
    SELECT id FROM location_cities
    WHERE province_id = ? AND LOWER(canonical_name) = LOWER(?)
    LIMIT 1
  ");

  $regionCount = 0;
  $provinceCount = 0;
  $cityCount = 0;

  foreach ($regions as $region) {
    $regionName = trim((string)($region["name"] ?? ""));
    if ($regionName === "") continue;

    $findRegion->execute([$regionName]);
    $regionId = $findRegion->fetchColumn();

    if (!$regionId) {
      $insertRegion->execute([$regionName]);
      $regionId = (int)$pdo->lastInsertId();
      $regionCount++;
    } else {
      $regionId = (int)$regionId;
    }

    echo "Region: {$regionName}\n";

    // 2) Provinces under this region
    $regionEncoded = rawurlencode($regionName);
    $provinces = fetch_json_or_fail("https://psgc.cloud/api/v2/regions/{$regionEncoded}/provinces");

    foreach ($provinces as $province) {
      $provinceName = trim((string)($province["name"] ?? ""));
      if ($provinceName === "") continue;

      $findProvince->execute([$regionId, $provinceName]);
      $provinceId = $findProvince->fetchColumn();

      if (!$provinceId) {
        $insertProvince->execute([$regionId, $provinceName]);
        $provinceId = (int)$pdo->lastInsertId();
        $provinceCount++;
      } else {
        $provinceId = (int)$provinceId;
      }

      add_province_aliases($pdo, $provinceId, $provinceName);

      echo "  Province: {$provinceName}\n";

      // 3) Cities & municipalities under this province within this region
      $provinceEncoded = rawurlencode($provinceName);
      $localities = fetch_json_or_fail("https://psgc.cloud/api/v2/regions/{$regionEncoded}/provinces/{$provinceEncoded}/cities-municipalities");

      foreach ($localities as $loc) {
        $localityName = trim((string)($loc["name"] ?? ""));
        $type = strtolower(trim((string)($loc["type"] ?? "")));

        if ($localityName === "") continue;

        $canonical = $localityName;

        if ($type === "city") {
          $lower = strtolower($canonical);
          if (!str_contains($lower, "city")) {
            $canonical .= " City";
          }
        }

        $findCity->execute([$provinceId, $canonical]);
        $cityId = $findCity->fetchColumn();

        if (!$cityId) {
          $insertCity->execute([$provinceId, $canonical]);
          $cityId = (int)$pdo->lastInsertId();
          $cityCount++;
        } else {
          $cityId = (int)$cityId;
        }

        add_city_aliases($pdo, $cityId, $canonical);
      }
    }
  }

  $pdo->commit();

  echo "\nImport completed successfully.\n";
  echo "New regions inserted: {$regionCount}\n";
  echo "New provinces inserted: {$provinceCount}\n";
  echo "New cities/municipalities inserted: {$cityCount}\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  http_response_code(500);
  echo "Import failed: " . $e->getMessage() . "\n";
}