<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function haversineMeters($lat1, $lng1, $lat2, $lng2) {
  $earth = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);

  $a = sin($dLat / 2) * sin($dLat / 2)
     + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
     * sin($dLng / 2) * sin($dLng / 2);

  return 2 * $earth * asin(min(1, sqrt($a)));
}

function normalize_narrative_for_match(string $text): string {
  $text = strtolower(trim($text));
  $text = preg_replace('/\s+/', ' ', $text);
  $text = preg_replace('/[^a-z0-9\s]/', '', $text);
  return trim($text);
}

function find_duplicate_candidates_for_admin(PDO $pdo, array $report): array {
  $incidentId = (int)($report["id"] ?? 0);
  $incidentType = trim((string)($report["incident_type"] ?? ""));
  $lat = isset($report["lat"]) ? (float)$report["lat"] : null;
  $lng = isset($report["lng"]) ? (float)$report["lng"] : null;
  $narrative = (string)($report["narrative"] ?? "");
  $dateIncidentFrom = (string)($report["date_incident_from"] ?? "");
  $createdAt = (string)($report["created_at"] ?? "");
  $duplicateOfId = (int)($report["duplicate_of_id"] ?? 0);

  if ($incidentId <= 0 || $incidentType === "" || $lat === null || $lng === null) {
    return [];
  }

  $baseTime = $dateIncidentFrom !== "" ? $dateIncidentFrom : $createdAt;
  if ($baseTime === "") {
    return [];
  }

  /*
    If current report is already marked as duplicate,
    use its original parent as the basis.
  */
  $basisId = $duplicateOfId > 0 ? $duplicateOfId : $incidentId;

  $stmt = $pdo->prepare("
    SELECT
      id,
      incident_code,
      reporter_user_id,
      incident_type,
      narrative,
      lat,
      lng,
      date_incident_from,
      created_at,
      verification_status,
      incident_phase,
      case_status,
      barangay,
      city_municipality,
      province,
      duplicate_of_id,
      duplicate_distance_m,
      duplicate_similarity,
      duplicate_time_diff_sec
    FROM incident_reports
    WHERE id <> ?
      AND LOWER(TRIM(incident_type)) = LOWER(TRIM(?))
      AND lat IS NOT NULL
      AND lng IS NOT NULL
      AND verification_status IN ('PENDING', 'VERIFIED', 'DUPLICATE')
      AND (
        id = ?
        OR duplicate_of_id = ?
        OR created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
      )
    ORDER BY created_at ASC
    LIMIT 80
  ");

  $stmt->execute([
    $incidentId,
    $incidentType,
    $basisId,
    $basisId
  ]);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $needle = normalize_narrative_for_match($narrative);
  $matches = [];

  foreach ($rows as $r) {
    $isAlreadyLinked =
      (int)$r["id"] === $basisId ||
      (int)($r["duplicate_of_id"] ?? 0) === $basisId;

    $distanceM = haversineMeters($lat, $lng, (float)$r["lat"], (float)$r["lng"]);

    $candidateBaseTime = (string)($r["date_incident_from"] ?: $r["created_at"]);
    $timeDiffSec = abs(strtotime($baseTime) - strtotime($candidateBaseTime));

  /*
    Always enforce distance and time threshold,
    even if the report is already linked by duplicate_of_id.
    This prevents old reports from previous years from showing
    as the Original Basis Report.
*/
    if ($distanceM > 200) {
      continue;
    }

    if ($timeDiffSec > 7200) {
      continue;
    }

    $existingNarrative = normalize_narrative_for_match((string)($r["narrative"] ?? ""));
    $similarity = null;

    if ($needle !== "" && $existingNarrative !== "") {
      similar_text($needle, $existingNarrative, $percent);
      $similarity = round($percent, 2);
    }

    $candidateRootId = (int)($r["duplicate_of_id"] ?? 0);
    if ($candidateRootId <= 0) {
      $candidateRootId = (int)$r["id"];
    }

    $matches[] = [
      "id" => (int)$r["id"],
      "incident_code" => $r["incident_code"],
      "reporter_user_id" => $r["reporter_user_id"] !== null ? (int)$r["reporter_user_id"] : null,
      "incident_type" => $r["incident_type"],
      "verification_status" => $r["verification_status"],
      "incident_phase" => $r["incident_phase"],
      "case_status" => $r["case_status"],
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "duplicate_of_id" => (int)($r["duplicate_of_id"] ?? 0),
      "basis_incident_id" => $candidateRootId,
      "is_basis_report" => (int)$r["id"] === $basisId,
      "is_already_linked" => $isAlreadyLinked,
      "distance_m" => (int)round($distanceM),
      "time_diff_sec" => (int)$timeDiffSec,
      "text_similarity" => $similarity,
      "rule_basis" => [
        "same_incident_type" => true,
        "distance_threshold_m" => 200,
        "time_threshold_sec" => 7200
      ]
    ];
  }

  return $matches;
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  out(400, ["ok" => false, "message" => "Missing id"]);
}

$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;

if ($role === "admin" && $stationId <= 0) {
  out(403, [
    "ok" => false,
    "message" => "Admin station is not configured."
  ]);
}

$sql = "
  SELECT
    r.*,
    ps.station_name AS assigned_station_name,
    ps.station_code AS assigned_station_code,
    u.firstname,
    u.lastname,
    u.email,
    u.username
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  LEFT JOIN police_stations ps ON ps.id = r.assigned_station_id
  WHERE r.id = :id
";

$params = [
  ":id" => $id
];

if ($role === "admin") {
  $sql .= " AND r.assigned_station_id = :station_id ";
  $params[":station_id"] = $stationId;
}

$sql .= " LIMIT 1 ";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  if ($k === ":id" || $k === ":station_id") {
    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $stmt->bindValue($k, $v);
  }
}
$stmt->execute();
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
  out(404, ["ok" => false, "message" => "Not found"]);
}

$pc = $pdo->prepare("SELECT COUNT(*) c FROM incident_report_photos WHERE incident_id = ?");
$pc->execute([$id]);
$photosCount = (int)($pc->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);

$duplicateCandidates = find_duplicate_candidates_for_admin($pdo, $r);
$duplicateCandidate = count($duplicateCandidates) > 0 ? $duplicateCandidates[0] : null;

echo json_encode([
  "ok" => true,
  "scope" => [
    "role" => $role,
    "station_id" => $role === "admin" ? $stationId : null,
    "is_global" => $role === "super_admin"
  ],
  "report" => [
    "id" => (int)$r["id"],
    "incident_code" => $r["incident_code"],
    "irf_entry_number" => $r["irf_entry_number"],
    "blotter_entry_number" => $r["blotter_entry_number"],
    "report_source" => $r["report_source"],
    "report_channel" => $r["report_channel"],
    "crime_type_id" => $r["crime_type_id"] !== null ? (int)$r["crime_type_id"] : null,
    "title" => $r["title"],
    "category" => $r["incident_type"],
    "incident_type" => $r["incident_type"],
    "crime_category" => $r["crime_category"],
    "focus_crime_code" => $r["focus_crime_code"],
    "ciras_offense_code" => $r["ciras_offense_code"],
    "description" => $r["narrative"],
    "narrative" => $r["narrative"],
    "date_reported" => $r["date_reported"],
    "date_incident_from" => $r["date_incident_from"],
    "date_incident_to" => $r["date_incident_to"],
    "place_of_incident" => $r["place_of_incident"],
    "sitio" => $r["sitio"],
    "barangay" => $r["barangay"],
    "city_municipality" => $r["city_municipality"],
    "province" => $r["province"],
    "region" => $r["region"],
    "assigned_station_id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
    "assigned_station_name" => $r["assigned_station_name"],
    "assigned_station_code" => $r["assigned_station_code"],
    "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
    "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
    "accuracy_m" => $r["accuracy_m"] !== null ? (int)$r["accuracy_m"] : null,
    "location_type" => $r["location_type"],
    "is_hotspot_related" => (int)$r["is_hotspot_related"],
    "hotspot_id" => $r["hotspot_id"] !== null ? (int)$r["hotspot_id"] : null,
    "risk_status" => $r["risk_status"],
    "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
    "risk_radius_m" => $r["risk_radius_m"] !== null ? (int)$r["risk_radius_m"] : null,
    "incident_phase" => $r["incident_phase"],
    "verification_status" => $r["verification_status"],
    "case_status" => $r["case_status"],
    "device_time" => $r["device_time"],
    "report_delay_minutes" => $r["report_delay_minutes"] !== null ? (int)$r["report_delay_minutes"] : null,
    "severity_score" => $r["severity_score"] !== null ? (float)$r["severity_score"] : null,
    "victim_count" => $r["victim_count"] !== null ? (int)$r["victim_count"] : 0,
    "suspect_count" => $r["suspect_count"] !== null ? (int)$r["suspect_count"] : 0,
    "witness_count" => $r["witness_count"] !== null ? (int)$r["witness_count"] : 0,
    "known_suspect" => isset($r["known_suspect"]) ? (int)$r["known_suspect"] : 0,
    "property_loss_flag" => isset($r["property_loss_flag"]) ? (int)$r["property_loss_flag"] : 0,
    "estimated_damage_value" => $r["estimated_damage_value"] !== null ? (float)$r["estimated_damage_value"] : null,
    "admin_notes" => $r["admin_notes"],
    "reviewed_by" => $r["reviewed_by"] !== null ? (int)$r["reviewed_by"] : null,
    "reviewed_at" => $r["reviewed_at"],
    "resolved_at" => $r["resolved_at"],
    "created_at" => $r["created_at"],
    "updated_at" => $r["updated_at"],
    "photos_count" => $photosCount,
    "reporter" => [
      "id" => $r["reporter_user_id"] !== null ? (int)$r["reporter_user_id"] : null,
      "firstname" => $r["firstname"],
      "lastname" => $r["lastname"],
      "email" => $r["email"],
      "username" => $r["username"]
    ],
    "duplicate_of_id" => $r["duplicate_of_id"] !== null ? (int)$r["duplicate_of_id"] : null,
    "duplicate_distance_m" => $r["duplicate_distance_m"] !== null ? (int)$r["duplicate_distance_m"] : null,
    "duplicate_similarity" => $r["duplicate_similarity"] !== null ? (float)$r["duplicate_similarity"] : null,
    "duplicate_time_diff_sec" => $r["duplicate_time_diff_sec"] !== null ? (int)$r["duplicate_time_diff_sec"] : null,
    "duplicate_candidate" => $duplicateCandidate ? [
      "exists" => true,
      "id" => $duplicateCandidate["id"],
      "incident_code" => $duplicateCandidate["incident_code"],
      "reporter_user_id" => $duplicateCandidate["reporter_user_id"],
      "incident_type" => $duplicateCandidate["incident_type"],
      "verification_status" => $duplicateCandidate["verification_status"],
      "incident_phase" => $duplicateCandidate["incident_phase"],
      "case_status" => $duplicateCandidate["case_status"],
      "barangay" => $duplicateCandidate["barangay"],
      "city_municipality" => $duplicateCandidate["city_municipality"],
      "province" => $duplicateCandidate["province"],
      "distance_m" => $duplicateCandidate["distance_m"],
      "time_diff_sec" => $duplicateCandidate["time_diff_sec"],
      "text_similarity" => $duplicateCandidate["text_similarity"],
      "rule_basis" => $duplicateCandidate["rule_basis"]
    ] : [
      "exists" => false
    ],
    "duplicate_candidates" => array_map(function($d) {
      return [
        "exists" => true,
        "id" => $d["id"],
        "incident_code" => $d["incident_code"],
        "reporter_user_id" => $d["reporter_user_id"],
        "incident_type" => $d["incident_type"],
        "verification_status" => $d["verification_status"],
        "incident_phase" => $d["incident_phase"],
        "case_status" => $d["case_status"],
        "barangay" => $d["barangay"],
        "city_municipality" => $d["city_municipality"],
        "province" => $d["province"],
        "duplicate_of_id" => $d["duplicate_of_id"],
        "basis_incident_id" => $d["basis_incident_id"],
        "is_basis_report" => $d["is_basis_report"],
        "is_already_linked" => $d["is_already_linked"],
        "distance_m" => $d["distance_m"],
        "time_diff_sec" => $d["time_diff_sec"],
        "text_similarity" => $d["text_similarity"],
        "rule_basis" => $d["rule_basis"]
      ];
    }, $duplicateCandidates)
      ]
]);