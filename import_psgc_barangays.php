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

$csvPath = __DIR__ . "/psgc_barangays.csv";

if (!file_exists($csvPath)) {
  out(404, ["ok" => false, "message" => "psgc_barangays.csv not found."]);
}

$handle = fopen($csvPath, "r");
if (!$handle) {
  out(500, ["ok" => false, "message" => "Unable to open CSV file."]);
}

$headers = fgetcsv($handle);
if (!$headers) {
  out(400, ["ok" => false, "message" => "CSV has no header row."]);
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

function retry_execute(PDOStatement $stmt, array $params, int $maxRetries = 3): void {
  $attempt = 0;

  while (true) {
    try {
      $stmt->execute($params);
      return;
    } catch (PDOException $e) {
      $attempt++;
      $sqlState = $e->getCode();
      $msg = $e->getMessage();

      $isDeadlock =
        $sqlState === "40001" ||
        str_contains($msg, "1213") ||
        str_contains(strtolower($msg), "deadlock");

      if (!$isDeadlock || $attempt >= $maxRetries) {
        throw $e;
      }

      usleep(200000 * $attempt);
    }
  }
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

  $cityCache = [];
  $missingCity = [];
  $processed = 0;
  $inserted = 0;
  $skipped = 0;
  $batchSize = 500;

  $pdo->beginTransaction();

  while (($row = fgetcsv($handle)) !== false) {
    $processed++;

    $province = col($row, $headerMap, ["province", "province_name", "prov_name"]);
    $city = col($row, $headerMap, [
      "city_municipality",
      "city_municipality_name",
      "city_mun",
      "municipality",
      "municipality_name",
      "city",
      "city_name"
    ]);
    $barangay = col($row, $headerMap, ["barangay", "barangay_name", "brgy", "brgy_name"]);

    if (!$province || !$city || !$barangay) {
      $skipped++;
      continue;
    }

    $cacheKey = strtolower($province . "|" . $city);

    if (array_key_exists($cacheKey, $cityCache)) {
      $cityId = $cityCache[$cacheKey];
    } else {
      retry_execute($findCityByAlias, [$province, $city, $city]);
      $cityId = $findCityByAlias->fetchColumn();
      $cityCache[$cacheKey] = $cityId ?: null;
    }

    if (!$cityId) {
      $missingCity[$cacheKey] = [
        "province" => $province,
        "city_municipality" => $city
      ];
      continue;
    }

    retry_execute($insertBarangay, [(int)$cityId, $barangay]);

    if ($insertBarangay->rowCount() > 0) {
      $inserted++;
    } else {
      $skipped++;
    }

    if ($processed % $batchSize === 0) {
      $pdo->commit();
      $pdo->beginTransaction();
    }
  }

  if ($pdo->inTransaction()) {
    $pdo->commit();
  }

  fclose($handle);

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
  if (is_resource($handle)) fclose($handle);
  if ($pdo->inTransaction()) $pdo->rollBack();

  out(500, [
    "ok" => false,
    "message" => "Import failed.",
    "debug" => $e->getMessage()
  ]);
}