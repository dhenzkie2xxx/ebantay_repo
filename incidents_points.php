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
  $verifiedPoints = [];
  $pendingMarkers = [];

  /**
   * INCIDENT REPORTS
   * VERIFIED -> heatmap
   * PENDING / UNDER_VERIFICATION -> map markers
   * FALSE_REPORT -> excluded
   * DUPLICATE -> deduplicated by exact data grouping
   */
  if ($category === "" || strcasecmp($category, "Panic") !== 0) {
    $incidentSql = "
      SELECT
        MIN(id) AS sample_id,
        lat,
        lng,
        incident_type AS category,
        verification_status,
        incident_phase,
        title,
        narrative,
        date_reported,
        date_incident_from,
        COUNT(*) AS duplicate_count
      FROM incident_reports
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND incident_phase <> 'REJECTED'
        AND verification_status <> 'FALSE_REPORT'
        AND date_reported >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        " . ($category !== "" ? " AND incident_type = ? " : "") . "
        $bboxSql
      GROUP BY
        lat,
        lng,
        incident_type,
        COALESCE(date_incident_from, date_reported),
        verification_status,
        incident_phase,
        COALESCE(title, ''),
        COALESCE(narrative, '')
    ";

    $params = [$days];
    if ($category !== "") $params[] = $category;
    $params = array_merge($params, $bboxParams);

    $stmt = $pdo->prepare($incidentSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $verificationStatus = strtoupper(trim($r["verification_status"] ?? ""));
      $incidentPhase = strtoupper(trim($r["incident_phase"] ?? ""));
      $dateBasis = $r["date_incident_from"] ?: $r["date_reported"];

      $weight = 1;
      if ($verificationStatus === "VERIFIED") $weight += 2;
      if (in_array($incidentPhase, ["BLOTTERED", "UNDER_INVESTIGATION", "FILED_IN_COURT"], true)) {
        $weight += 1;
      }

      $ageSec = time() - strtotime($dateBasis);
      if ($ageSec <= 86400) $weight += 2;
      else if ($ageSec <= 7 * 86400) $weight += 1;

      $basePoint = [
        "id" => (int)$r["sample_id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => $r["category"] ?: "Other",
        "title" => $r["title"],
        "narrative" => $r["narrative"],
        "date_reported" => $r["date_reported"],
        "date_incident_from" => $r["date_incident_from"],
        "verification_status" => $verificationStatus,
        "incident_phase" => $incidentPhase,
        "duplicate_count" => (int)$r["duplicate_count"],
        "source" => "incident_report",
      ];

      // VERIFIED = heatmap only
      if ($verificationStatus === "VERIFIED") {
        $verifiedPoints[] = array_merge($basePoint, [
          "weight" => $weight
        ]);
        continue;
      }

      // FALSE_REPORT already excluded above
      // PENDING / UNDER_VERIFICATION / not yet verified = marker
      if (in_array($verificationStatus, ["PENDING", "DUPLICATE"], true) || $incidentPhase === "UNDER_VERIFICATION") {
        $pendingMarkers[] = array_merge($basePoint, [
          "marker_type" => "report",
          "icon" => "description"
        ]);
      }
    }
  }

  /**
   * PANIC REQUESTS
   * always markers, not heatmap
   */
  if ($category === "" || strcasecmp($category, "Panic") === 0) {
    $panicSql = "
      SELECT
        MIN(id) AS sample_id,
        lat,
        lng,
        level,
        created_at,
        COUNT(*) AS duplicate_count
      FROM panic_requests
      WHERE
        lat IS NOT NULL
        AND lng IS NOT NULL
        AND status <> 'resolved'
        AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
        $bboxSql
      GROUP BY
        lat,
        lng,
        level,
        created_at
    ";

    $params = array_merge([$days], $bboxParams);
    $stmt = $pdo->prepare($panicSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
      $pendingMarkers[] = [
        "id" => (int)$r["sample_id"],
        "lat" => (float)$r["lat"],
        "lng" => (float)$r["lng"],
        "category" => "Panic",
        "level" => $r["level"],
        "created_at" => $r["created_at"],
        "duplicate_count" => (int)$r["duplicate_count"],
        "marker_type" => "panic",
        "icon" => "priority-high",
        "source" => "panic_request",
      ];
    }
  }

  if ($group === 1) {
    $grouped = [];
    foreach ($verifiedPoints as $p) {
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
    "count" => count($verifiedPoints),
    "points" => $verifiedPoints,          // heatmap only
    "pending_markers" => $pendingMarkers, // marker icons
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}