<?php
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function clean_text($value): ?string {
  $value = trim((string)($value ?? ""));
  $value = preg_replace('/\s+/', ' ', $value);
  return $value === "" ? null : $value;
}

/*
|--------------------------------------------------------------------------
| How to use
|--------------------------------------------------------------------------
| 1. Download the PSGC publication datafile from PSA:
|    https://psa.gov.ph/classification/psgc
|
| 2. Open the Excel file.
|
| 3. Export/save the barangay sheet as:
|    psgc_barangays.csv
|
| 4. Put psgc_barangays.csv in the same folder as this PHP file.
|
| 5. Visit:
|    https://your-api-domain.com/import_psgc_barangays.php
|
| 6. Delete/protect this file after import.
|
| Expected CSV columns can be flexible. The script looks for headers like:
| - province
| - city / municipality
| - barangay
|--------------------------------------------------------------------------
*/

$csvPath = __DIR__ . "/psgc_barangays.csv";

if (!file_exists($csvPath)) {
  out(404, [
    "ok" => false,
    "message" => "psgc_barangays.csv not found in API folder."
  ]);
}

$handle = fopen($csvPath, "r");
if (!$handle) {
  out(500, [
    "ok" => false,
    "message" => "Unable to open CSV file."
  ]);
}

$headers = fgetcsv($handle);
if (!$headers) {
  out(400, [
    "ok" => false,
    "message" => "CSV has no header row."
  ]);
}

$headerMap = [];
foreach ($headers as $i => $h) {
  $key = strtolower(trim((string)$h));
  $key = preg_replace('/[^a-z0-9]+/', '_', $key);
  $key = trim($key, '_');
  $headerMap[$key] = $i;
}

function col(array $row, array $headerMap, array $possibleKeys): ?string {
  foreach ($possibleKeys as $key) {
    if (array_key_exists($key, $headerMap)) {
      return clean_text($row[$headerMap[$key]] ?? null);
    }
  }
  return null;
}

try {
  $pdo->beginTransaction();

  $findCity = $pdo->prepare("
    SELECT c.id
    FROM location_cities c
    INNER JOIN location_provinces p
      ON p.id = c.province_id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
    LIMIT 1
  ");

  $findCityByAlias = $pdo->prepare("
    SELECT c.id
    FROM location_cities c
    INNER JOIN location_provinces p
      ON p.id = c.province_id
    LEFT JOIN location_city_aliases a
      ON a.city_id = c.id
    WHERE LOWER(TRIM(p.canonical_name)) = LOWER(TRIM(?))
      AND (
        LOWER(TRIM(c.canonical_name)) = LOWER(TRIM(?))
        OR LOWER(TRIM(a.alias_name)) = LOWER(TRIM(?))
      )
    LIMIT 1
  ");

  $insertBarangay = $pdo->prepare("
    INSERT IGNORE INTO location_barangays (
      city_id,
      canonical_name
    )
    VALUES (?, ?)
  ");

  $inserted = 0;
  $skipped = 0;
  $missingCity = [];
  $processed = 0;

  while (($row = fgetcsv($handle)) !== false) {
    $processed++;

    $province = col($row, $headerMap, [
      "province",
      "province_name",
      "prov_name"
    ]);

    $city = col($row, $headerMap, [
      "city_municipality",
      "city_municipality_name",
      "city_mun",
      "municipality",
      "municipality_name",
      "city",
      "city_name"
    ]);

    $barangay = col($row, $headerMap, [
      "barangay",
      "barangay_name",
      "brgy",
      "brgy_name"
    ]);

    /*
     * Some PSGC exports use "Geographic Name" and "Geographic Level".
     * If your CSV uses that format, barangay rows must have level = Barangay,
     * while province/city columns should still exist from the publication sheet.
     */
    $geoLevel = col($row, $headerMap, [
      "geographic_level",
      "level",
      "geo_level"
    ]);

    $geoName = col($row, $headerMap, [
      "geographic_name",
      "name"
    ]);

    if (!$barangay && $geoLevel && strtolower($geoLevel) === "barangay") {
      $barangay = $geoName;
    }

    if (!$province || !$city || !$barangay) {
      $skipped++;
      continue;
    }

    $findCity->execute([$province, $city]);
    $cityId = $findCity->fetchColumn();

    if (!$cityId) {
      $findCityByAlias->execute([$province, $city, $city]);
      $cityId = $findCityByAlias->fetchColumn();
    }

    if (!$cityId) {
      $missingKey = strtolower($province . "|" . $city);
      $missingCity[$missingKey] = [
        "province" => $province,
        "city_municipality" => $city
      ];
      continue;
    }

    $insertBarangay->execute([
      (int)$cityId,
      $barangay
    ]);

    if ($insertBarangay->rowCount() > 0) {
      $inserted++;
    } else {
      $skipped++;
    }
  }

  fclose($handle);
  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "PSGC barangay import completed.",
    "processed_rows" => $processed,
    "inserted" => $inserted,
    "skipped_or_existing" => $skipped,
    "missing_city_count" => count($missingCity),
    "missing_city" => array_values($missingCity)
  ]);

} catch (Throwable $e) {
  if (is_resource($handle)) {
    fclose($handle);
  }

  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Import failed.",
    "debug" => $e->getMessage()
  ]);
}