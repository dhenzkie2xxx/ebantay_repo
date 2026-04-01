<?php
require_once __DIR__ . "/db.php";

// 🔒 VERY IMPORTANT: protect this
$secret = $_GET["key"] ?? "";
if ($secret !== "IMPORT_2026_SECRET") {
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// remove time limits (important)
set_time_limit(0);

function fetch_json($url) {
  $json = file_get_contents($url);
  return json_decode($json, true);
}

echo "Starting import...\n";

// 1. Regions
$regions = fetch_json("https://psgc.gitlab.io/api/regions/");
foreach ($regions as $r) {
  $stmt = $pdo->prepare("INSERT IGNORE INTO location_regions (canonical_name) VALUES (?)");
  $stmt->execute([$r["name"]]);
}

// 2. Provinces
$provinces = fetch_json("https://psgc.gitlab.io/api/provinces/");
foreach ($provinces as $p) {
  $regionName = $p["region"]["name"] ?? null;

  $stmt = $pdo->prepare("SELECT id FROM location_regions WHERE canonical_name = ?");
  $stmt->execute([$regionName]);
  $regionId = $stmt->fetchColumn();

  if (!$regionId) continue;

  $stmt = $pdo->prepare("
    INSERT IGNORE INTO location_provinces (region_id, canonical_name)
    VALUES (?, ?)
  ");
  $stmt->execute([$regionId, $p["name"]]);
}

// 3. Cities + Municipalities
$cities = fetch_json("https://psgc.gitlab.io/api/cities/");
$municipalities = fetch_json("https://psgc.gitlab.io/api/municipalities/");

$allCities = array_merge($cities, $municipalities);

foreach ($allCities as $c) {
  $provinceName = $c["province"]["name"] ?? null;

  $stmt = $pdo->prepare("SELECT id FROM location_provinces WHERE canonical_name = ?");
  $stmt->execute([$provinceName]);
  $provinceId = $stmt->fetchColumn();

  if (!$provinceId) continue;

  $canonical = $c["name"];

  // Ensure "City" suffix for cities
  if (($c["type"] ?? "") === "city" && stripos($canonical, "City") === false) {
    $canonical .= " City";
  }

  $stmt = $pdo->prepare("
    INSERT IGNORE INTO location_cities (province_id, canonical_name)
    VALUES (?, ?)
  ");
  $stmt->execute([$provinceId, $canonical]);

  $cityId = $pdo->lastInsertId();
  if (!$cityId) {
    $stmt = $pdo->prepare("SELECT id FROM location_cities WHERE canonical_name = ?");
    $stmt->execute([$canonical]);
    $cityId = $stmt->fetchColumn();
  }

  // aliases
  $aliases = [
    strtolower($canonical),
    str_replace(" city", "", strtolower($canonical)),
    "city of " . str_replace(" city", "", strtolower($canonical))
  ];

  foreach ($aliases as $alias) {
    $stmt = $pdo->prepare("
      INSERT IGNORE INTO location_city_aliases (city_id, alias_name)
      VALUES (?, ?)
    ");
    $stmt->execute([$cityId, $alias]);
  }
}

echo "Import completed!";