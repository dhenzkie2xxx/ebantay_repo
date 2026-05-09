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

function duplicate_crime_group(string $incidentType): string {
  $name = strtolower(trim($incidentType));

  if ($name === "") return "";

  if (str_contains($name, "murder") || str_contains($name, "homicide")) return "death_related";
  if (str_contains($name, "robbery") || str_contains($name, "theft")) return "property_taking";
  if (str_contains($name, "physical injury")) return "physical_injury";
  if (str_contains($name, "rape") || str_contains($name, "lasciviousness")) return "sexual_offense";
  if (str_contains($name, "carnapping")) return "carnapping";
  if (str_contains($name, "drug")) return "drug_related";
  if (str_contains($name, "firearm") || str_contains($name, "firearms")) return "firearms_related";
  if (str_contains($name, "cybercrime") || str_contains($name, "10175")) return "cybercrime";

  return $name;
}

function has_duplicate_candidate(PDO $pdo, array $row): bool {
  if (
    !isset($row["id"], $row["incident_type"], $row["lat"], $row["lng"]) ||
    $row["lat"] === null ||
    $row["lng"] === null ||
    trim((string)$row["incident_type"]) === ""
  ) {
    return false;
  }

  $targetCrimeGroup = duplicate_crime_group((string)$row["incident_type"]);

  $stmt = $pdo->prepare("
    SELECT
      id,
      incident_type,
      lat,
      lng,
      date_incident_from,
      created_at
    FROM incident_reports
    WHERE id <> ?
      AND verification_status IN ('PENDING', 'VERIFIED', 'DUPLICATE')
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
    ORDER BY created_at DESC
    LIMIT 30
  ");

  $stmt->execute([
    (int)$row["id"]
  ]);

  $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $baseTime = strtotime((string)($row["date_incident_from"] ?: $row["created_at"]));
  if ($baseTime === false) {
    return false;
  }

  foreach ($candidates as $c) {
    if (duplicate_crime_group((string)$c["incident_type"]) !== $targetCrimeGroup) {
      continue;
    }

    if ($c["lat"] === null || $c["lng"] === null) continue;

    $distanceM = haversineMeters(
      (float)$row["lat"],
      (float)$row["lng"],
      (float)$c["lat"],
      (float)$c["lng"]
    );

    if ($distanceM > 200) continue;

    $candidateTime = strtotime((string)($c["date_incident_from"] ?: $c["created_at"]));
    if ($candidateTime === false) continue;

    if (abs($baseTime - $candidateTime) <= 7200) {
      return true;
    }
  }

  return false;
}

$verification_status = strtoupper(trim($_GET["status"] ?? "ALL"));
$allowed = ["ALL", "PENDING", "VERIFIED", "FALSE_REPORT", "DUPLICATE"];
if (!in_array($verification_status, $allowed, true)) $verification_status = "ALL";

$limit = (int)($_GET["limit"] ?? 10);
if ($limit < 1) $limit = 10;
if ($limit > 200) $limit = 200;

$page = (int)($_GET["page"] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$q = trim($_GET["q"] ?? "");
$category = trim($_GET["category"] ?? "");

$role = (string)($AUTH_USER["role"] ?? "");
$stationId = isset($AUTH_USER["station_id"]) ? (int)$AUTH_USER["station_id"] : 0;

if ($role === "admin" && $stationId <= 0) {
  out(403, [
    "ok" => false,
    "message" => "Admin station is not configured."
  ]);
}

$where = " WHERE 1=1 ";
$params = [];

if ($role === "admin") {
  $where .= " AND r.assigned_station_id = :station_id ";
  $params[":station_id"] = $stationId;
}

if ($verification_status !== "ALL") {
  $where .= " AND r.verification_status = :verification_status ";
  $params[":verification_status"] = $verification_status;
}

if ($category !== "") {
  $where .= " AND (r.incident_type = :category OR r.crime_category = :category) ";
  $params[":category"] = $category;
}

if ($q !== "") {
  $where .= " AND (
    r.incident_code LIKE :q
    OR r.title LIKE :q
    OR r.narrative LIKE :q
    OR r.incident_type LIKE :q
    OR r.crime_category LIKE :q
    OR u.firstname LIKE :q
    OR u.lastname LIKE :q
    OR u.email LIKE :q
    OR r.barangay LIKE :q
    OR r.city_municipality LIKE :q
    OR r.province LIKE :q
  ) ";
  $params[":q"] = "%{$q}%";
}

$countSql = "
  SELECT COUNT(*) AS total
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  $where
";

$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) {
  if ($k === ":station_id") {
    $countStmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $countStmt->bindValue($k, $v);
  }
}
$countStmt->execute();
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)["total"] ?? 0);

$listSql = "
  SELECT
    r.id,
    r.incident_code,
    r.title,
    r.incident_type,
    r.crime_category,
    r.narrative,
    r.risk_status,
    r.risk_distance_m,
    r.risk_radius_m,
    r.is_hotspot_related,
    r.hotspot_id,
    r.assigned_station_id,
    ps.station_name AS assigned_station_name,
    ps.station_code AS assigned_station_code,
    r.lat,
    r.lng,
    r.barangay,
    r.city_municipality,
    r.province,
    r.region,
    r.created_at,
    r.date_reported,
    r.date_incident_from,
    r.verification_status,
    r.incident_phase,
    r.case_status,
    r.duplicate_of_id,
    r.duplicate_distance_m,
    r.duplicate_similarity,
    r.duplicate_time_diff_sec,
    r.admin_notes,
    r.reviewed_at,
    r.resolved_at,
    u.id AS user_id,
    u.firstname,
    u.lastname,
    u.email,
    (
      SELECT COUNT(*)
      FROM incident_report_photos p
      WHERE p.incident_id = r.id
    ) AS photo_count
  FROM incident_reports r
  LEFT JOIN users u ON u.id = r.reporter_user_id
  LEFT JOIN police_stations ps ON ps.id = r.assigned_station_id
  $where
  ORDER BY r.created_at DESC
  LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
  if ($k === ":station_id") {
    $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
  } else {
    $stmt->bindValue($k, $v);
  }
}
$stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
$stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,
  "filters" => [
    "status" => $verification_status,
    "category" => $category,
    "q" => $q,
    "page" => $page,
    "limit" => $limit
  ],
  "scope" => [
    "role" => $role,
    "station_id" => $role === "admin" ? $stationId : null,
    "is_global" => $role === "super_admin"
  ],
  "pagination" => [
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => $limit > 0 ? (int)ceil($total / $limit) : 1
  ],
  "reports" => array_map(function($r) use ($pdo) {
    return [
      "id" => (int)$r["id"],
      "incident_code" => $r["incident_code"],
      "title" => $r["title"],
      "incident_type" => $r["incident_type"],
      "crime_category" => $r["crime_category"],
      "narrative" => $r["narrative"],
      "risk_status" => $r["risk_status"],
      "risk_distance_m" => $r["risk_distance_m"] !== null ? (int)$r["risk_distance_m"] : null,
      "risk_radius_m" => $r["risk_radius_m"] !== null ? (int)$r["risk_radius_m"] : null,
      "is_hotspot_related" => (int)$r["is_hotspot_related"],
      "hotspot_id" => $r["hotspot_id"] !== null ? (int)$r["hotspot_id"] : null,
      "assigned_station_id" => $r["assigned_station_id"] !== null ? (int)$r["assigned_station_id"] : null,
      "assigned_station_name" => $r["assigned_station_name"],
      "assigned_station_code" => $r["assigned_station_code"],
      "lat" => $r["lat"] !== null ? (float)$r["lat"] : null,
      "lng" => $r["lng"] !== null ? (float)$r["lng"] : null,
      "barangay" => $r["barangay"],
      "city_municipality" => $r["city_municipality"],
      "province" => $r["province"],
      "region" => $r["region"],
      "created_at" => $r["created_at"],
      "date_reported" => $r["date_reported"],
      "date_incident_from" => $r["date_incident_from"],
      "verification_status" => $r["verification_status"],
      "incident_phase" => $r["incident_phase"],
      "case_status" => $r["case_status"],
      "duplicate_of_id" => $r["duplicate_of_id"] !== null ? (int)$r["duplicate_of_id"] : null,
      "duplicate_distance_m" => $r["duplicate_distance_m"] !== null ? (int)$r["duplicate_distance_m"] : null,
      "duplicate_similarity" => $r["duplicate_similarity"] !== null ? (float)$r["duplicate_similarity"] : null,
      "duplicate_time_diff_sec" => $r["duplicate_time_diff_sec"] !== null ? (int)$r["duplicate_time_diff_sec"] : null,
      "admin_notes" => $r["admin_notes"],
      "reviewed_at" => $r["reviewed_at"],
      "resolved_at" => $r["resolved_at"],
      "photo_count" => (int)$r["photo_count"],
      "has_duplicate_candidate" => ((int)($r["duplicate_of_id"] ?? 0) > 0) || has_duplicate_candidate($pdo, $r),
      "reporter" => [
        "id" => $r["user_id"] !== null ? (int)$r["user_id"] : null,
        "firstname" => $r["firstname"],
        "lastname" => $r["lastname"],
        "email" => $r["email"]
      ]
    ];
  }, $rows)
]);