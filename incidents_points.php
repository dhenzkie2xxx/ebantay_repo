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
  $heatPoints = [];
  $pendingMarkers = [];

  /**
   * VERIFIED INCIDENTS -> HEATMAP
   * DUPLICATES are collapsed by lat/lng/category
   */
  if ($category === "" || strcasecmp($category, "Panic") !== 0) {
    $verifiedSql = "
      SELECT
        lat,
        lng,
        incident_type AS category,
        COUNT(*) AS total_reports,
        MAX(date_reported) AS latest_reported,
        SUM(CASE WHEN verification_status = 'VERIFIED' THEN 1 ELSE 0 END) AS verified_count,
        SUM(CASE WHEN incident_phase IN ('BLOTTERED', 'UNDER_INVESTIGATION', 'FILED_IN_COURT') THEN 1 ELSE 0 END) AS escalated_count,
        SUM(CASE WHEN is_hotspot_related = 1 THEN 1 ELSE 0 END) AS hotspot_related_count
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status = 'VERIFIED'
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        " . ($category !== "" ? " AND incident_type = ? " : "") . "
        $bboxSql
      GROUP BY lat, lng, incident_type
    ";

    $params = [$days];
    if ($category !== "") $params[] = $category;
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($verifiedSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $w = 1;

      $verifiedCount = (int)($r["verified_count"] ?? 0);
      $escalatedCount = (int)($r["escalated_count"] ?? 0);
      $hotspotRelatedCount = (int)($r["hotspot_related_count"] ?? 0);
      $totalReports = (int)($r["total_reports"] ?? 1);

      if ($verifiedCount > 0) $w += 1;
      if ($escalatedCount > 0) $w += 1;
      if ($hotspotRelatedCount > 0) $w += 1;
      if ($totalReports >= 2) $w += 1;
      if ($totalReports >= 3) $w += 1;

      $ageSec = time() - strtotime($r["latest_reported"]);
      if ($ageSec <= 86400) $w += 2;
      else if ($ageSec <= 7 * 86400) $w += 1;

      $heatPoints[] = [
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "weight" => $w,
        "category" => $r["category"] ?: "Other",
        "source" => "incident_report",
      ];
    }

    /**
     * PENDING INCIDENTS -> MARKERS
     * FALSE_REPORT hidden
     * DUPLICATE hidden
     * grouped so exact duplicates only show once
     */
    $pendingSql = "
      SELECT
        lat,
        lng,
        incident_type AS category,
        MIN(id) AS id,
        MAX(date_reported) AS latest_reported
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status = 'PENDING'
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        " . ($category !== "" ? " AND incident_type = ? " : "") . "
        $bboxSql
      GROUP BY lat, lng, incident_type
    ";

    $params = [$days];
    if ($category !== "") $params[] = $category;
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($pendingSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $pendingMarkers[] = [
        "id" => (int)$r["id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => $r["category"] ?: "Other",
        "marker_type" => "report",
        "verification_status" => "PENDING",
        "source" => "incident_report",
      ];
    }
  }

  /**
   * PANIC REQUESTS -> MARKERS
   */
  if ($category === "" || strcasecmp($category, "Panic") === 0) {
    $panicSql = "
      SELECT
        lat,
        lng,
        level,
        MIN(id) AS id,
        MAX(created_at) AS latest_created_at
      FROM panic_requests
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND status <> 'resolved'
        AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        $bboxSql
      GROUP BY lat, lng, level
    ";

    $params = array_merge([$days], $bboxParams);
    $stmt = $pdo->prepare($panicSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $pendingMarkers[] = [
        "id" => (int)$r["id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => "Panic",
        "marker_type" => "panic",
        "level" => $r["level"] ?: "alert",
        "source" => "panic_request",
      ];
    }
  }

  if ($group === 1) {
    $grouped = [];
    foreach ($heatPoints as $p) {
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
      "pending_markers" => $pendingMarkers,
    ]);
  }

  out(200, [
    "ok" => true,
    "days" => $days,
    "grouped" => false,
    "count" => count($heatPoints),
    "points" => $heatPoints,
    "pending_markers" => $pendingMarkers,
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage(),
  ]);
}