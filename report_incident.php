<?php
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function normalize_text($value) {
  return trim((string)($value ?? ""));
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

$placeOfIncident  = normalize_text($_POST["place_of_incident"] ?? "");
$sitio            = normalize_text($_POST["sitio"] ?? "");
$barangay         = normalize_text($_POST["barangay"] ?? "");
$cityMunicipality = normalize_text($_POST["city_municipality"] ?? "");
$province         = normalize_text($_POST["province"] ?? "");
$region           = normalize_text($_POST["region"] ?? "");

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
  $barangay === "" ||
  $cityMunicipality === "" ||
  $province === "" ||
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
  /*
  |--------------------------------------------------------------------------
  | AUTH USER
  |--------------------------------------------------------------------------
  */
  $q = $pdo->prepare("
    SELECT id, firstname, lastname, email, api_token_expires, valid
    FROM users
    WHERE api_token = ?
    LIMIT 1
  ");
  $q->execute([$token]);
  $user = $q->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (($user["valid"] ?? "") !== "valid") {
    out(403, [
      "ok" => false,
      "message" => "Your account is not yet activated. Please complete account setup or contact the administrator."
    ]);
  }

  $exp = !empty($user["api_token_expires"]) ? strtotime($user["api_token_expires"]) : 0;
  if ($exp > 0 && time() > $exp) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

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
  | REPORT DELAY
  |--------------------------------------------------------------------------
  */
  $reportDelayMinutes = null;
  if ($dateIncidentFromSql) {
    $delay = (strtotime($dateReportedSql) - strtotime($dateIncidentFromSql)) / 60;
    $reportDelayMinutes = max(0, (int)round($delay));
  }

  $incidentCode = generate_incident_code($pdo);

  $pdo->beginTransaction();

  /*
  |--------------------------------------------------------------------------
  | INSERT INCIDENT REPORT
  |--------------------------------------------------------------------------
  */
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
      created_at,
      updated_at
    ) VALUES (
      ?, ?, 'mobile_app', 'mobile', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'GPS',
      ?, ?, ?, ?, ?, 'REPORTED', 'PENDING', 'OPEN', ?, ?, NOW(), NOW()
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
    ($placeOfIncident !== "" ? $placeOfIncident : null),
    ($sitio !== "" ? $sitio : null),
    $barangay,
    $cityMunicipality,
    $province,
    ($region !== "" ? $region : null),
    $lat,
    $lng,
    $accuracy,
    $isHotspotRelated,
    $hotspotId,
    $riskStatus,
    $riskDistanceM,
    $riskRadiusM,
    $deviceTimeSql,
    $reportDelayMinutes
  ]);

  $incidentId = (int)$pdo->lastInsertId();

  /*
  |--------------------------------------------------------------------------
  | INSERT REPORTING PERSON
  |--------------------------------------------------------------------------
  */
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

  /*
  |--------------------------------------------------------------------------
  | STATUS HISTORY
  |--------------------------------------------------------------------------
  */
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
    'Incident reported from mobile app',
    (int)$user["id"]
  ]);

  /*
  |--------------------------------------------------------------------------
  | PHOTOS
  |--------------------------------------------------------------------------
  */
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

      if (strpos($mime, "image/") !== 0) continue;

      $maxBytes = 6 * 1024 * 1024;
      if ($size > $maxBytes) continue;

      $bytes = file_get_contents($tmp);
      if ($bytes === false || $bytes === "") continue;

      $sha = hash("sha256", $bytes);

      $thumbBytes = null;
      $thumbMime = null;

      if (extension_loaded("gd")) {
        $srcImg = @imagecreatefromstring($bytes);
        if ($srcImg !== false) {
          $w = imagesx($srcImg);
          $h = imagesy($srcImg);

          $maxW = 480;
          $scale = $w > 0 ? min(1, $maxW / $w) : 1;
          $tw = (int)max(1, round($w * $scale));
          $th = (int)max(1, round($h * $scale));

          $dstImg = imagecreatetruecolor($tw, $th);
          imagealphablending($dstImg, false);
          imagesavealpha($dstImg, true);

          imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $tw, $th, $w, $h);

          ob_start();
          imagejpeg($dstImg, null, 75);
          $thumbBytes = ob_get_clean();
          $thumbMime = "image/jpeg";

          imagedestroy($dstImg);
          imagedestroy($srcImg);
        }
      }

      $capturedAt = $deviceTimeSql ?: $dateReportedSql;

      $photoStmt->execute([
        $incidentId,
        $mime,
        $name,
        $size,
        $bytes,
        $thumbBytes,
        $thumbMime,
        $sha,
        $capturedAt
      ]);
    }
  }

  $pdo->commit();

  out(200, [
    "ok" => true,
    "message" => "Report submitted successfully",
    "incident_id" => $incidentId,
    "incident_code" => $incidentCode,
    "risk_status" => $riskStatus,
    "risk_distance_m" => $riskDistanceM,
    "risk_radius_m" => $riskRadiusM,
    "is_hotspot_related" => $isHotspotRelated
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