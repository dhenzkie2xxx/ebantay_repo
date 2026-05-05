<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try {
  $radiusM = 500;
  $minIncidents = 2;

  $stmt = $pdo->query("
    SELECT
      id,
      incident_code,
      incident_type,
      crime_category,
      severity_score,
      lat,
      lng,
      region,
      province,
      city_municipality,
      barangay,
      date_reported
    FROM incident_reports
    WHERE verification_status = 'VERIFIED'
      AND incident_phase IN ('RESOLVED','UNDER_INVESTIGATION','BLOTTERED','FILED_IN_COURT')
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND province = 'Misamis Occidental'
      AND city_municipality = 'Tangub City'
    ORDER BY date_reported ASC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $created = [];
  $usedIncidentIds = [];

  foreach ($rows as $base) {
    $baseId = (int)$base["id"];
    if (isset($usedIncidentIds[$baseId])) continue;

    $cluster = [];
    $latSum = 0;
    $lngSum = 0;
    $severityTotal = 0;
    $maxWeight = 0;
    $barangayCounts = [];

    foreach ($rows as $r) {
      $rid = (int)$r["id"];

      $dist = hotspot_distance_meters(
        (float)$base["lat"],
        (float)$base["lng"],
        (float)$r["lat"],
        (float)$r["lng"]
      );

      if ($dist <= $radiusM) {
        $cluster[] = [
          "row" => $r,
          "distance_m" => $dist
        ];

        $latSum += (float)$r["lat"];
        $lngSum += (float)$r["lng"];

        $weight = hotspot_crime_weight(
          $r["incident_type"] ?? "",
          $r["crime_category"] ?? "",
          $r["severity_score"] ?? null
        );

        $severityTotal += $weight;
        $maxWeight = max($maxWeight, $weight);

        $bgy = trim((string)($r["barangay"] ?? ""));
        if ($bgy !== "") {
          $barangayCounts[$bgy] = ($barangayCounts[$bgy] ?? 0) + 1;
        }
      }
    }

    if (count($cluster) < $minIncidents && $maxWeight < 8) {
      continue;
    }

    arsort($barangayCounts);
    $dominantBarangay = array_key_first($barangayCounts) ?: ($base["barangay"] ?? "Unknown");

    $centerLat = $latSum / count($cluster);
    $centerLng = $lngSum / count($cluster);

    $color = hotspot_compute_color(count($cluster), 0, $severityTotal, $maxWeight);
    $riskLevel = hotspot_compute_risk_level($color);

    $name = "Auto Hotspot - {$dominantBarangay} / Tangub City";

    $insert = $pdo->prepare("
      INSERT INTO crime_hotspots
      (
        name,
        region,
        province,
        city_municipality,
        barangay,
        lat,
        lng,
        radius_m,
        hotspot_type,
        risk_level,
        active,
        last_detected_at,
        created_at
      )
      VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, 'CRIME_CLUSTER', ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");

    $insert->execute([
      $name,
      $base["region"],
      $base["province"],
      $base["city_municipality"],
      $dominantBarangay,
      $centerLat,
      $centerLng,
      $radiusM,
      $riskLevel
    ]);

    $hotspotId = (int)$pdo->lastInsertId();

    $update = $pdo->prepare("
      UPDATE incident_reports
      SET
        is_hotspot_related = 1,
        hotspot_id = ?,
        risk_status = 'RISK',
        risk_distance_m = ?,
        risk_radius_m = ?
      WHERE id = ?
    ");

    foreach ($cluster as $c) {
      $incidentId = (int)$c["row"]["id"];
      $usedIncidentIds[$incidentId] = true;

      $update->execute([
        $hotspotId,
        (int)round($c["distance_m"]),
        $radiusM,
        $incidentId
      ]);
    }

    $created[] = [
      "hotspot_id" => $hotspotId,
      "name" => $name,
      "barangay" => $dominantBarangay,
      "incident_count" => count($cluster),
      "severity_total" => round($severityTotal, 2),
      "max_weight" => round($maxWeight, 2),
      "risk_level" => $riskLevel
    ];
  }

  echo json_encode([
    "ok" => true,
    "eligible_incidents" => count($rows),
    "created_count" => count($created),
    "created" => $created
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage(),
    "line" => $e->getLine()
  ]);
}