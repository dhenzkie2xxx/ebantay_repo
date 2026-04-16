<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/location_resolver.php";
require_once __DIR__ . "/station_assignment_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_text($value) {
  return trim((string)($value ?? ""));
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function parse_datetime_to_sql($value) {
  $value = trim((string)$value);
  if ($value === "") return null;

  $ts = strtotime($value);
  if ($ts === false) return null;

  return date("Y-m-d H:i:s", $ts);
}

function generate_incident_code(PDO $pdo) {
  do {
    $code = "INC-" . date("YmdHis") . "-" . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare("SELECT id FROM incident_reports WHERE incident_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
  } while ($exists);

  return $code;
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

function compute_severity_score(string $crimeCategory, int $victimCount, int $suspectCount, int $propertyLossFlag, string $riskStatus, int $isHotspotRelated): float {
  $crimeWeights = [
    'INDEX' => 3.0,
    'SPECIAL_LAW' => 2.5,
    'NON_INDEX' => 2.0,
    'OTHER' => 1.5,
  ];

  $C = $crimeWeights[strtoupper($crimeCategory)] ?? 1.5;
  $V = max(0, $victimCount) * 0.5;
  $S = max(0, $suspectCount) * 0.5;
  $P = $propertyLossFlag ? 1.0 : 0.0;
  $R = (strtoupper($riskStatus) === 'RISK' || (int)$isHotspotRelated === 1) ? 1.0 : 0.0;

  return round($C + $V + $S + $P + $R, 2);
}

function find_recent_same_user_incident(PDO $pdo, int $userId, string $incidentType, float $lat, float $lng, string $dateIncidentFromSql, int $windowMinutes = 20): ?array {
  $stmt = $pdo->prepare("\n    SELECT id, incident_code, incident_type, lat, lng, date_incident_from, created_at\n    FROM incident_reports\n    WHERE reporter_user_id = ?\n      AND LOWER(TRIM(incident_type)) = LOWER(TRIM(?))\n      AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)\n      AND verification_status <> 'FALSE_REPORT'\n    ORDER BY created_at DESC\n    LIMIT 10\n  ");
  $stmt->execute([$userId, $incidentType, $windowMinutes]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $d = haversineMeters($lat, $lng, (float)$r['lat'], (float)$r['lng']);
    $timeDiff = abs(strtotime((string)$dateIncidentFromSql) - strtotime((string)($r['date_incident_from'] ?: $r['created_at'])));
    if ($d <= 150 && $timeDiff <= ($windowMinutes * 60)) {
      $r['distance_m'] = (int) round($d);
      return $r;
    }
  }
  return null;
}

function find_duplicate_incident(PDO $pdo, int $userId, string $incidentType, string $narrative, float $lat, float $lng, string $dateIncidentFromSql): ?array {
  $stmt = $pdo->prepare("\n    SELECT id, incident_code, reporter_user_id, incident_type, narrative, lat, lng, date_incident_from, created_at\n    FROM incident_reports\n    WHERE reporter_user_id <> ?\n      AND LOWER(TRIM(incident_type)) = LOWER(TRIM(?))\n      AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)\n      AND verification_status IN ('PENDING','VERIFIED','DUPLICATE')\n    ORDER BY created_at DESC\n    LIMIT 50\n  ");
  $stmt->execute([$userId, $incidentType]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $needle = normalize_narrative_for_match($narrative);
  foreach ($rows as $r) {
    $d = haversineMeters($lat, $lng, (float)$r['lat'], (float)$r['lng']);
    if ($d > 200) continue;
    $timeDiff = abs(strtotime((string)$dateIncidentFromSql) - strtotime((string)($r['date_incident_from'] ?: $r['created_at'])));
    if ($timeDiff > (60 * 60 * 2)) continue;
    $existing = normalize_narrative_for_match((string)$r['narrative']);
    similar_text($needle, $existing, $percent);
    if ($needle !== '' && $existing !== '' && $percent >= 78) {
      $r['distance_m'] = (int) round($d);
      $r['text_similarity'] = round($percent, 2);
      return $r;
    }
  }
  return null;
}

function reverse_geocode_scope(float $lat, float $lng): array {
  $url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&lat="
    . urlencode((string)$lat)
    . "&lon="
    . urlencode((string)$lng);

  $opts = [
    "http" => [
      "method" => "GET",
      "header" =>
        "User-Agent: eBantay/1.0\r\n" .
        "Accept: application/json\r\n",
      "timeout" => 15
    ]
  ];

  $context = stream_context_create($opts);
  $raw = @file_get_contents($url, false, $context);
  if ($raw === false) {
    return [
      "ok" => false,
      "message" => "Reverse geocoding service unavailable"
    ];
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) {
    return [
      "ok" => false,
      "message" => "Invalid geocoding response"
    ];
  }

  $addr = $json["address"] ?? [];

  $barangay = $addr["suburb"]
    ?? $addr["village"]
    ?? $addr["hamlet"]
    ?? $addr["neighbourhood"]
    ?? $addr["quarter"]
    ?? $addr["city_district"]
    ?? "";

  $cityMunicipality = $addr["city"]
    ?? $addr["municipality"]
    ?? $addr["town"]
    ?? "";

  $state = trim((string)($addr["state"] ?? ""));
  $region = $addr["region"]
    ?? $addr["state_district"]
    ?? "";

  $province = $addr["province"]
    ?? $addr["county"]
    ?? "";

  if ($province === "" && $state !== "") {
    $stateLower = strtolower($state);
    $looksLikeRegion =
      str_contains($stateLower, "region") ||
      str_contains($stateLower, "national capital region") ||
      $stateLower === "ncr" ||
      str_contains($stateLower, "metro manila");

    if ($looksLikeRegion) {
      if ($region === "") $region = $state;
    } else {
      $province = $state;
    }
  }

  $road = $addr["road"] ?? "";
  $displayName = $json["display_name"] ?? "";

  return [
    "ok" => true,
    "address" => [
      "barangay" => normalize_scope_value($barangay),
      "city_municipality" => normalize_scope_value($cityMunicipality),
      "province" => normalize_scope_value($province),
      "region" => normalize_scope_value($region),
      "place_of_incident" => normalize_scope_value($road),
      "display_name" => normalize_scope_value($displayName)
    ]
  ];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

/*
|--------------------------------------------------------------------------
| INPUTS
|--------------------------------------------------------------------------
*/
$token            = normalize_text($_POST["token"] ?? "");
$title            = normalize_text($_POST["title"] ?? "");
$crimeTypeIdRaw   = $_POST["crime_type_id"] ?? null;
$incidentTypeRaw  = normalize_text($_POST["incident_type"] ?? "");
$narrative        = normalize_text($_POST["narrative"] ?? "");

$placeOfIncidentClient  = normalize_text($_POST["place_of_incident"] ?? "");
$sitioClient            = normalize_text($_POST["sitio"] ?? "");
$barangayClient         = normalize_text($_POST["barangay"] ?? "");
$cityMunicipalityClient = normalize_text($_POST["city_municipality"] ?? "");
$provinceClient         = normalize_text($_POST["province"] ?? "");
$regionClient           = normalize_text($_POST["region"] ?? "");

$lat              = $_POST["lat"] ?? null;
$lng              = $_POST["lng"] ?? null;
$accuracy         = $_POST["accuracy"] ?? null;

$dateIncidentFromRaw = normalize_text($_POST["date_incident_from"] ?? "");
$dateIncidentToRaw   = normalize_text($_POST["date_incident_to"] ?? "");
$deviceTimeRaw       = normalize_text($_POST["device_time"] ?? "");

$clientRiskStatus    = strtoupper(normalize_text($_POST["risk_status"] ?? "SAFE"));
$clientRiskDistanceM = $_POST["risk_distance_m"] ?? null;
$clientRiskRadiusM   = $_POST["risk_radius_m"] ?? 250;

if (
  $token === "" ||
  $narrative === "" ||
  $lat === null ||
  $lng === null
) {
  out(400, ["ok" => false, "message" => "Missing required fields"]);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
  out(400, ["ok" => false, "message" => "Invalid coordinates"]);
}

$lat = (float)$lat;
$lng = (float)$lng;

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
  out(400, ["ok" => false, "message" => "Coordinates out of range"]);
}

$crimeTypeId = (is_numeric($crimeTypeIdRaw) && (int)$crimeTypeIdRaw > 0) ? (int)$crimeTypeIdRaw : null;
$accuracy = is_numeric($accuracy) ? (int)round((float)$accuracy) : null;
$clientRiskDistanceM = is_numeric($clientRiskDistanceM) ? (int)$clientRiskDistanceM : null;
$clientRiskRadiusM   = is_numeric($clientRiskRadiusM) ? (int)$clientRiskRadiusM : 250;

$dateReportedSql     = date("Y-m-d H:i:s");
$dateIncidentFromSql = parse_datetime_to_sql($dateIncidentFromRaw);
$dateIncidentToSql   = parse_datetime_to_sql($dateIncidentToRaw);
$deviceTimeSql       = parse_datetime_to_sql($deviceTimeRaw);

if ($dateIncidentFromSql === null) {
  $dateIncidentFromSql = $deviceTimeSql ?: $dateReportedSql;
}

if ($dateIncidentToSql !== null && strtotime($dateIncidentToSql) < strtotime($dateIncidentFromSql)) {
  out(400, ["ok" => false, "message" => "Incident end time cannot be earlier than start time"]);
}

try {
  $user = auth_get_user_by_token($pdo, $token);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (($user["valid"] ?? "") !== "valid") {
    out(403, [
      "ok" => false,
      "message" => "Your account is not yet activated. Please complete account setup or contact the administrator."
    ]);
  }

  if (auth_check_token_expired($user)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  /*
  |--------------------------------------------------------------------------
  | RESOLVE SERVER-SIDE LOCATION SCOPE
  |--------------------------------------------------------------------------
  */
  $geo = reverse_geocode_scope($lat, $lng);

  $barangay = null;
  $cityMunicipality = null;
  $province = null;
  $region = null;
  $placeOfIncident = null;
  $scopeSource = "client_fallback";

  if ($geo["ok"]) {
    $addr = $geo["address"];
    $barangay = $addr["barangay"] ?? null;
    $cityMunicipality = $addr["city_municipality"] ?? null;
    $province = $addr["province"] ?? null;
    $region = $addr["region"] ?? null;
    $placeOfIncident = $addr["place_of_incident"] ?? null;
    $scopeSource = "reverse_geocode";
  }

  if (!$barangay) $barangay = normalize_scope_value($barangayClient);
  if (!$cityMunicipality) $cityMunicipality = normalize_scope_value($cityMunicipalityClient);
  if (!$province) $province = normalize_scope_value($provinceClient);
  if (!$region) $region = normalize_scope_value($regionClient);
  if (!$placeOfIncident) $placeOfIncident = normalize_scope_value($placeOfIncidentClient);
  $sitio = normalize_scope_value($sitioClient);

  if (!$barangay || !$cityMunicipality || !$province) {
    out(422, [
      "ok" => false,
      "message" => "Unable to determine complete incident location scope"
    ]);
  }

  $canon = canonicalize_scope($pdo, $region, $province, $cityMunicipality);
  if (!$canon["ok"]) {
    out(422, [
      "ok" => false,
      "message" => $canon["message"]
    ]);
  }

  $region = $canon["region"];
  $province = $canon["province"];
  $cityMunicipality = $canon["city_municipality"];

  /*
  |--------------------------------------------------------------------------
  | RESOLVE CRIME TYPE
  |--------------------------------------------------------------------------
  */
  $resolvedCrimeTypeId = null;
  $incidentType = "";
  $crimeCategory = "OTHER";
  $focusCrimeCode = null;
  $cirasOffenseCode = null;

  if ($crimeTypeId !== null) {
    $crimeStmt = $pdo->prepare("
      SELECT id, crime_name, crime_category, focus_crime_code, ciras_offense_code
      FROM crime_types
      WHERE id = ? AND is_active = 1
      LIMIT 1
    ");
    $crimeStmt->execute([$crimeTypeId]);
    $crimeRow = $crimeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$crimeRow) {
      out(400, ["ok" => false, "message" => "Invalid crime type selected"]);
    }

    $resolvedCrimeTypeId = (int)$crimeRow["id"];
    $incidentType = $crimeRow["crime_name"];
    $crimeCategory = $crimeRow["crime_category"] ?: "OTHER";
    $focusCrimeCode = $crimeRow["focus_crime_code"] ?: null;
    $cirasOffenseCode = $crimeRow["ciras_offense_code"] ?: null;
  } else {
    if ($incidentTypeRaw === "") {
      out(400, ["ok" => false, "message" => "Incident type is required"]);
    }

    $incidentType = $incidentTypeRaw;
    $crimeCategory = "OTHER";
  }

  /*
  |--------------------------------------------------------------------------
  | PREVENT SAME-ACCOUNT MULTIPLE INCIDENTS WITHIN 20 MINUTES
  |--------------------------------------------------------------------------
  */
  $existingRecent = find_recent_same_user_incident($pdo, (int)$user["id"], $incidentType, $lat, $lng, $dateIncidentFromSql, 20);
  if ($existingRecent) {
    out(429, [
      "ok" => false,
      "message" => "You already submitted a similar incident report within the last 20 minutes.",
      "code" => "RECENT_DUPLICATE_BY_SAME_USER",
      "existing_report" => [
        "id" => (int)$existingRecent["id"],
        "incident_code" => $existingRecent["incident_code"],
        "distance_m" => (int)($existingRecent["distance_m"] ?? 0)
      ]
    ]);
  }

  /*
  |--------------------------------------------------------------------------
  | DUPLICATE CHECK AGAINST OTHER CITIZENS
  |--------------------------------------------------------------------------
  */
  $duplicateOf = find_duplicate_incident($pdo, (int)$user["id"], $incidentType, $narrative, $lat, $lng, $dateIncidentFromSql);

  /*
  |--------------------------------------------------------------------------
  | HOTSPOT CHECK
  |--------------------------------------------------------------------------
  */
  $riskStatus = $clientRiskStatus === "RISK" ? "RISK" : "SAFE";
  $riskDistanceM = $clientRiskDistanceM;
  $riskRadiusM = $clientRiskRadiusM > 0 ? $clientRiskRadiusM : 250;
  $hotspotId = null;
  $isHotspotRelated = 0;

  $hotspotsStmt = $pdo->query("
    SELECT id, lat, lng, radius_m
    FROM crime_hotspots
    WHERE active = 1
  ");
  $hotspots = $hotspotsStmt->fetchAll(PDO::FETCH_ASSOC);

  if ($hotspots) {
    $nearestDist = null;
    $nearestId = null;
    $nearestRadius = 250;

    foreach ($hotspots as $h) {
      $d = haversineMeters($lat, $lng, (float)$h["lat"], (float)$h["lng"]);
      if ($nearestDist === null || $d < $nearestDist) {
        $nearestDist = $d;
        $nearestId = (int)$h["id"];
        $nearestRadius = (int)$h["radius_m"];
      }
    }

    if ($nearestDist !== null) {
      $riskDistanceM = (int)round($nearestDist);
      $riskRadiusM = $nearestRadius > 0 ? $nearestRadius : 250;
      $riskStatus = ($nearestDist <= $riskRadiusM) ? "RISK" : "SAFE";

      if ($riskStatus === "RISK") {
        $isHotspotRelated = 1;
        $hotspotId = $nearestId;
      }
    }
  }

  /*
  |--------------------------------------------------------------------------
  | ASSIGN INCIDENT STATION
  |--------------------------------------------------------------------------
  */
  $assignedStation = assign_incident_station($pdo, $lat, $lng, $province, $cityMunicipality);
  $assignedStationId = $assignedStation ? (int)$assignedStation["id"] : null;
  $assignmentRule = $assignedStation["_assignment_rule"] ?? null;

  /*
  |--------------------------------------------------------------------------
  | REPORT DELAY
  |--------------------------------------------------------------------------
  */
  $reportDelayMinutes = null;
  if ($dateIncidentFromSql) {
    $delay = (strtotime($dateReportedSql) - strtotime($dateIncidentFromSql)) / 60;
    $reportDelayMinutes = max(0, (int)round($delay));
  }

  $severityScore = compute_severity_score($crimeCategory, 0, 0, 0, $riskStatus, $isHotspotRelated);

  $incidentCode = generate_incident_code($pdo);

  $pdo->beginTransaction();

  $stmt = $pdo->prepare("
    INSERT INTO incident_reports (
      incident_code,
      reporter_user_id,
      report_source,
      report_channel,
      crime_type_id,
      incident_type,
      crime_category,
      focus_crime_code,
      ciras_offense_code,
      title,
      narrative,
      date_reported,
      date_incident_from,
      date_incident_to,
      place_of_incident,
      sitio,
      barangay,
      city_municipality,
      province,
      region,
      assigned_station_id,
      lat,
      lng,
      accuracy_m,
      location_type,
      is_hotspot_related,
      hotspot_id,
      risk_status,
      risk_distance_m,
      risk_radius_m,
      incident_phase,
      verification_status,
      case_status,
      device_time,
      report_delay_minutes,
      severity_score,
      created_at,
      updated_at
    ) VALUES (
      ?, ?, 'mobile_app', 'mobile', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'GPS',
      ?, ?, ?, ?, ?, 'REPORTED', ?, 'OPEN', ?, ?, ?, NOW(), NOW()
    )
  ");

  $stmt->execute([
    $incidentCode,
    (int)$user["id"],
    $resolvedCrimeTypeId,
    $incidentType,
    $crimeCategory,
    $focusCrimeCode,
    $cirasOffenseCode,
    ($title !== "" ? $title : null),
    $narrative,
    $dateReportedSql,
    $dateIncidentFromSql,
    $dateIncidentToSql,
    $placeOfIncident,
    $sitio,
    $barangay,
    $cityMunicipality,
    $province,
    $region,
    $assignedStationId,
    $lat,
    $lng,
    $accuracy,
    $isHotspotRelated,
    $hotspotId,
    $riskStatus,
    $riskDistanceM,
    $riskRadiusM,
    $duplicateOf ? 'DUPLICATE' : 'PENDING',
    $deviceTimeSql,
    $reportDelayMinutes,
    $severityScore
  ]);

  $incidentId = (int)$pdo->lastInsertId();

  $personStmt = $pdo->prepare("
    INSERT INTO incident_persons (
      incident_id,
      person_role,
      linked_user_id,
      family_name,
      first_name,
      email_address,
      created_at
    ) VALUES (
      ?, 'REPORTING_PERSON', ?, ?, ?, ?, NOW()
    )
  ");

  $personStmt->execute([
    $incidentId,
    (int)$user["id"],
    $user["lastname"] ?? null,
    $user["firstname"] ?? null,
    $user["email"] ?? null
  ]);

  $statusStmt = $pdo->prepare("
    INSERT INTO incident_status_history (
      incident_id,
      old_phase,
      new_phase,
      old_case_status,
      new_case_status,
      old_verification_status,
      new_verification_status,
      remarks,
      changed_by,
      changed_at
    ) VALUES (
      ?, NULL, 'REPORTED', NULL, 'OPEN', NULL, 'PENDING', ?, ?, NOW()
    )
  ");

  $statusStmt->execute([
    $incidentId,
    $duplicateOf ? 'Incident reported from mobile app and auto-flagged as DUPLICATE candidate' : 'Incident reported from mobile app',
    (int)$user["id"]
  ]);

  if (!empty($_FILES["photos"]) && is_array($_FILES["photos"]["tmp_name"])) {
    $count = count($_FILES["photos"]["tmp_name"]);
    $max = 5;
    if ($count > $max) $count = $max;

    $photoStmt = $pdo->prepare("
      INSERT INTO incident_report_photos (
        incident_id,
        mime_type,
        file_name,
        file_size,
        image,
        thumb,
        thumb_mime_type,
        sha256,
        photo_role,
        captured_at,
        created_at
      ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, 'EVIDENCE', ?, NOW()
      )
    ");

    for ($i = 0; $i < $count; $i++) {
      $err = $_FILES["photos"]["error"][$i];
      if ($err !== UPLOAD_ERR_OK) continue;

      $tmp = $_FILES["photos"]["tmp_name"][$i];
      if (!is_uploaded_file($tmp)) continue;

      $size = (int)($_FILES["photos"]["size"][$i] ?? 0);
      $name = $_FILES["photos"]["name"][$i] ?? null;

      $mime = null;
      if (function_exists("finfo_open")) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $mime = finfo_file($finfo, $tmp);
          finfo_close($finfo);
        }
      }
      if (!$mime) $mime = $_FILES["photos"]["type"][$i] ?? "application/octet-stream";

      $imageData = @file_get_contents($tmp);
      if ($imageData === false || $imageData === "") continue;

      $thumbData = null;
      $thumbMime = $mime;

      if (function_exists("imagecreatefromstring")) {
        $src = @imagecreatefromstring($imageData);
        if ($src !== false) {
          $srcW = imagesx($src);
          $srcH = imagesy($src);
          $maxW = 480;
          $maxH = 480;

          $ratio = min($maxW / max(1, $srcW), $maxH / max(1, $srcH), 1);
          $newW = max(1, (int)round($srcW * $ratio));
          $newH = max(1, (int)round($srcH * $ratio));

          $dst = imagecreatetruecolor($newW, $newH);
          imagealphablending($dst, false);
          imagesavealpha($dst, true);

          $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
          imagefill($dst, 0, 0, $transparent);

          imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

          ob_start();
          imagejpeg($dst, null, 82);
          $thumbData = ob_get_clean();
          $thumbMime = "image/jpeg";

          imagedestroy($dst);
          imagedestroy($src);
        }
      }

      $sha256 = hash("sha256", $imageData);
      $capturedAt = $deviceTimeSql ?: $dateReportedSql;

      $photoStmt->execute([
        $incidentId,
        $mime,
        $name,
        $size,
        $imageData,
        $thumbData,
        $thumbMime,
        $sha256,
        $capturedAt
      ]);
    }
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Incident reported successfully",
    "incident_id" => $incidentId,
    "incident_code" => $incidentCode,
    "scope" => [
      "source" => $scopeSource,
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "barangay" => $barangay,
      "place_of_incident" => $placeOfIncident
    ],
    "assignment" => [
      "assigned_station_id" => $assignedStationId,
      "rule" => $assignmentRule,
      "station" => $assignedStation ? [
        "id" => (int)$assignedStation["id"],
        "station_name" => $assignedStation["station_name"] ?? null,
        "station_code" => $assignedStation["station_code"] ?? null,
        "station_type" => $assignedStation["station_type"] ?? null,
        "province" => $assignedStation["province"] ?? null,
        "city_municipality" => $assignedStation["city_municipality"] ?? null,
        "barangay" => $assignedStation["barangay"] ?? null,
        "full_address" => $assignedStation["full_address"] ?? null,
        "distance_m" => isset($assignedStation["distance_m"]) ? (int)$assignedStation["distance_m"] : null
      ] : null
    ],
    "risk" => [
      "status" => $riskStatus,
      "distance_m" => $riskDistanceM,
      "radius_m" => $riskRadiusM,
      "hotspot_id" => $hotspotId,
      "is_hotspot_related" => $isHotspotRelated
    ],
    "severity_score" => $severityScore,
    "duplicate_flag" => $duplicateOf ? [
      "is_duplicate" => true,
      "matched_incident_id" => (int)$duplicateOf["id"],
      "matched_incident_code" => $duplicateOf["incident_code"],
      "distance_m" => (int)($duplicateOf["distance_m"] ?? 0),
      "text_similarity" => (float)($duplicateOf["text_similarity"] ?? 0)
    ] : [
      "is_duplicate" => false
    ]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}