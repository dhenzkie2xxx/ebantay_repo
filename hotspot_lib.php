<?php

function hotspot_distance_meters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a = sin($dLat / 2) * sin($dLat / 2) +
       cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
       sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

function compute_hotspot_color($incidentCount, $panicCount) {
  if ($incidentCount >= 3 || $panicCount >= 1) return "red";
  if ($incidentCount >= 1) return "green";
  return "none";
}

function compute_hotspot_risk_level($color) {
  if ($color === "red") return "HIGH";
  if ($color === "green") return "LOW";
  return "LOW";
}

function get_computed_hotspots(PDO $pdo, int $days = 30): array {
  $days = max(1, min(365, $days));

  $hotspotsStmt = $pdo->query("
    SELECT id, name, lat, lng, radius_m, hotspot_type, risk_level, last_detected_at, created_at
    FROM crime_hotspots
    WHERE active = 1
    ORDER BY id DESC
  ");
  $hotspots = $hotspotsStmt->fetchAll(PDO::FETCH_ASSOC);

  $incidentStmt = $pdo->prepare("
    SELECT
      lat,
      lng,
      date_reported
    FROM incident_reports
    WHERE
      lat IS NOT NULL
      AND lng IS NOT NULL
      AND incident_phase <> 'REJECTED'
      AND verification_status NOT IN ('FALSE_REPORT', 'DUPLICATE')
      AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ");
  $incidentStmt->execute([$days]);
  $incidentRows = $incidentStmt->fetchAll(PDO::FETCH_ASSOC);

  $panicStmt = $pdo->prepare("
    SELECT
      lat,
      lng,
      level,
      created_at,
      status
    FROM panic_requests
    WHERE
      lat IS NOT NULL
      AND lng IS NOT NULL
      AND status <> 'resolved'
      AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
  ");
  $panicStmt->execute([$days]);
  $panicRows = $panicStmt->fetchAll(PDO::FETCH_ASSOC);

  $out = [];

  foreach ($hotspots as $h) {
    $hLat = (float)$h["lat"];
    $hLng = (float)$h["lng"];
    $radius = (int)$h["radius_m"];

    $incidentCount = 0;
    $panicCount = 0;
    $panicScore = 0;
    $lastDetected = null;

    foreach ($incidentRows as $r) {
      $d = hotspot_distance_meters($hLat, $hLng, (float)$r["lat"], (float)$r["lng"]);
      if ($d <= $radius) {
        $incidentCount++;
        if ($lastDetected === null || strtotime($r["date_reported"]) > strtotime($lastDetected)) {
          $lastDetected = $r["date_reported"];
        }
      }
    }

    foreach ($panicRows as $p) {
      $d = hotspot_distance_meters($hLat, $hLng, (float)$p["lat"], (float)$p["lng"]);
      if ($d <= $radius) {
        $panicCount++;
        $panicScore += (($p["level"] ?? "") === "urgent") ? 2 : 1;
        if ($lastDetected === null || strtotime($p["created_at"]) > strtotime($lastDetected)) {
          $lastDetected = $p["created_at"];
        }
      }
    }

    $color = compute_hotspot_color($incidentCount, $panicCount);
    $riskLevel = compute_hotspot_risk_level($color);
    $score = $incidentCount + $panicScore;

    $out[] = [
      "id" => (int)$h["id"],
      "name" => $h["name"],
      "lat" => $hLat,
      "lng" => $hLng,
      "radius_m" => $radius,
      "hotspot_type" => $h["hotspot_type"],
      "risk_level" => $riskLevel,
      "highlight_color" => $color,
      "incident_count" => $incidentCount,
      "panic_count" => $panicCount,
      "panic_score" => $panicScore,
      "score" => $score,
      "last_detected_at" => $lastDetected,
      "created_at" => $h["created_at"],
    ];
  }

  usort($out, function ($a, $b) {
    $rank = ["red" => 3, "green" => 2, "none" => 1];
    $ra = $rank[$a["highlight_color"]] ?? 0;
    $rb = $rank[$b["highlight_color"]] ?? 0;
    if ($ra !== $rb) return $rb <=> $ra;
    return ($b["score"] ?? 0) <=> ($a["score"] ?? 0);
  });

  return $out;
}

function find_nearest_hotspot(array $hotspots, float $lat, float $lng): ?array {
  $nearest = null;
  $nearestD = null;

  foreach ($hotspots as $h) {
    $d = hotspot_distance_meters($lat, $lng, (float)$h["lat"], (float)$h["lng"]);
    if ($nearestD === null || $d < $nearestD) {
      $nearestD = $d;
      $nearest = $h;
      $nearest["distance_m"] = (int)round($d);
      $nearest["is_inside"] = $d <= (float)$h["radius_m"];
    }
  }

  return $nearest;
}