<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
$days = max(1, min(365, $days));

$category = trim($_GET["category"] ?? "");

/* Normalize category */
if ($category !== "") {
  $category = mb_substr($category, 0, 100);
}

$group = isset($_GET["group"]) ? (int)$_GET["group"] : 0;

$minLat = isset($_GET["minLat"]) ? (float)$_GET["minLat"] : null;
$maxLat = isset($_GET["maxLat"]) ? (float)$_GET["maxLat"] : null;
$minLng = isset($_GET["minLng"]) ? (float)$_GET["minLng"] : null;
$maxLng = isset($_GET["maxLng"]) ? (float)$_GET["maxLng"] : null;

$bboxSql = "";
$bboxParams = [];
if ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null) {
  $bboxSql = " AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? ";
  $bboxParams = [$minLat, $maxLat, $minLng, $maxLng];
}

try {
  $points = [];

  if ($category === "" || strcasecmp($category, "Panic") !== 0) {
    $incidentSql = "
      SELECT
        lat,
        lng,
        incident_type AS category,
        date_reported,
        incident_phase,
        verification_status,
        is_hotspot_related
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status NOT IN ('FALSE_REPORT', 'DUPLICATE')
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        " . ($category !== "" ? " AND LOWER(incident_type) = LOWER(?) " : "") . "
        $bboxSql
    ";

    $params = [$days];
    if ($category !== "") $params[] = $category;
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($incidentSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $w = 1;

      if (($r["verification_status"] ?? "") === "VERIFIED") $w += 1;
      if (in_array($r["incident_phase"] ?? "", ["BLOTTERED", "UNDER_INVESTIGATION", "FILED_IN_COURT"], true)) {
        $w += 1;
      }
      if ((int)($r["is_hotspot_related"] ?? 0) === 1) $w += 1;

      $ageSec = time() - strtotime($r["date_reported"]);
      if ($ageSec <= 86400) $w += 2;
      else if ($ageSec <= 7 * 86400) $w += 1;

      $points[] = [
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "weight" => $w,
        "category" => $r["category"] ?: "Other",
        "source" => "incident_report",
      ];
    }
  }

  if ($category === "" || strcasecmp($category, "Panic") === 0) {
    $panicSql = "
      SELECT
        lat,
        lng,
        level,
        created_at
      FROM panic_requests
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND status <> 'resolved'
        AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        $bboxSql
    ";

    $params = array_merge([$days], $bboxParams);
    $stmt = $pdo->prepare($panicSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $w = ($r["level"] === "urgent") ? 5 : 3;

      $ageSec = time() - strtotime($r["created_at"]);
      if ($ageSec <= 86400) $w += 1;

      $points[] = [
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "weight" => $w,
        "category" => "Panic",
        "source" => "panic_request",
      ];
    }
  }

  if ($group === 1) {
    $grouped = [];
    foreach ($points as $p) {
      $cat = $p["category"] ?? "Unknown";
      if (!isset($grouped[$cat])) $grouped[$cat] = [];
      $grouped[$cat][] = [
        "lat" => $p["lat"],
        "lng" => $p["lng"],
        "weight" => $p["weight"],
      ];
    }

    out(200, [
      "ok" => true,
      "days" => $days,
      "grouped" => true,
      "data" => $grouped,
    ]);
  }

  out(200, [
    "ok" => true,
    "days" => $days,
    "grouped" => false,
    "count" => count($points),
    "points" => $points,
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}