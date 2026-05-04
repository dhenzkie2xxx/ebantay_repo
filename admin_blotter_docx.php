<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/location_resolver.php";

function out_error($code, $message, $debug = null) {
  http_response_code($code);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode([
    "ok" => false,
    "message" => $message,
    "debug" => $debug
  ]);
  exit;
}

function norm_text($v): ?string {
  $v = trim((string)($v ?? ""));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v === "" ? null : $v;
}

function xml_safe($v): string {
  return htmlspecialchars((string)($v ?? ""), ENT_QUOTES | ENT_XML1, "UTF-8");
}

function fmt_dt($v): string {
  if (!$v) return "";
  $ts = strtotime($v);
  if ($ts === false) return (string)$v;
  return date("m/d/Y h:i A", $ts);
}

function yes_no($v): string {
  return ((int)$v === 1) ? "Yes" : "No";
}

function current_admin_scope(PDO $pdo, array $AUTH_USER): array {
  $role = (string)($AUTH_USER["role"] ?? "");

  if ($role === "super_admin") {
    return [
      "is_super_admin" => true,
      "province" => null,
      "city_municipality" => null
    ];
  }

  $canon = canonicalize_scope(
    $pdo,
    norm_text($AUTH_USER["station_region"] ?? null),
    norm_text($AUTH_USER["station_province"] ?? null),
    norm_text($AUTH_USER["station_city_municipality"] ?? null)
  );

  if (empty($canon["ok"])) {
    throw new Exception("Unable to resolve station scope.");
  }

  return [
    "is_super_admin" => false,
    "province" => $canon["province"],
    "city_municipality" => $canon["city_municipality"]
  ];
}

function first_by_role(array $rows, string $role): ?array {
  foreach ($rows as $r) {
    if (strtoupper((string)($r["person_role"] ?? "")) === strtoupper($role)) {
      return $r;
    }
  }
  return null;
}

function first_officer_by_role(array $rows, string $role): ?array {
  foreach ($rows as $r) {
    if (strtoupper((string)($r["officer_role"] ?? "")) === strtoupper($role)) {
      return $r;
    }
  }
  return null;
}

function person_full_name(?array $p): string {
  if (!$p) return "";
  return trim(
    ($p["first_name"] ?? "") . " " .
    ($p["middle_name"] ?? "") . " " .
    ($p["family_name"] ?? "")
  );
}

function officer_full_name(?array $o): string {
  if (!$o) return "";
  return trim(($o["rank_title"] ?? "") . " " . ($o["full_name"] ?? ""));
}

function person_vars(?array $p): array {
  $p = $p ?: [];

  $fields = [
    "id", "incident_id", "person_role", "linked_user_id",
    "family_name", "first_name", "middle_name", "qualifier", "nickname",
    "citizenship", "sex_gender", "civil_status", "birth_date", "age",
    "place_of_birth", "home_phone", "mobile_phone", "mobile_number",
    "email_address", "current_address", "current_text", "current_sitio",
    "current_barangay", "current_city", "current_province",
    "other_address", "other_sitio", "other_barangay", "other_city",
    "other_province", "educational_attainment", "occupation",
    "work_address", "relation_to_victim", "is_afp_pnp_personnel",
    "rank_title", "unit_assignment", "group_affiliation",
    "has_previous_criminal_record", "previous_case_status",
    "height_cm", "weight_kg", "built", "eye_color", "eye_description",
    "hair_color", "hair_description", "under_influence",
    "under_influence_notes", "guardian_name", "guardian_address",
    "guardian_home_phone", "guardian_mobile_phone", "suspect_status",
    "created_at"
  ];

  $out = [];

  foreach ($fields as $f) {
    if ($f === "mobile_number") {
      $out[$f] = $p["mobile_phone"] ?? "";
    } elseif ($f === "current_text") {
      $out[$f] = $p["current_address"] ?? "";
    } elseif ($f === "has_previous_criminal_record" || $f === "is_afp_pnp_personnel") {
      $out[$f] = yes_no($p[$f] ?? 0);
    } else {
      $out[$f] = $p[$f] ?? "";
    }
  }

  $out["full_name"] = person_full_name($p);

  return $out;
}

function officer_vars(?array $o): array {
  $o = $o ?: [];

  return [
    "id" => $o["id"] ?? "",
    "incident_id" => $o["incident_id"] ?? "",
    "officer_role" => $o["officer_role"] ?? "",
    "user_id" => $o["user_id"] ?? "",
    "rank_title" => $o["rank_title"] ?? "",
    "full_name" => $o["full_name"] ?? "",
    "name_with_rank" => officer_full_name($o),
    "designation" => $o["designation"] ?? "",
    "police_station" => $o["police_station"] ?? "",
    "mobile_phone" => $o["mobile_phone"] ?? "",
    "signature_ref" => $o["signature_ref"] ?? "",
    "created_at" => $o["created_at"] ?? ""
  ];
}

function merge_prefixed_vars(array &$vars, string $prefix, array $data): void {
  foreach ($data as $k => $v) {
    $vars[$prefix . $k] = $v;
  }
}

function replace_placeholders_in_xml(string $xml, array $vars): string {
  return preg_replace_callback('/\{\{.*?\}\}/su', function ($m) use ($vars) {
    $raw = $m[0];

    // Handles placeholders split by Word XML runs:
    // {{*family</w:t><w:t>_name}}
    $key = strip_tags($raw);
    $key = html_entity_decode($key, ENT_QUOTES | ENT_XML1, "UTF-8");
    $key = str_replace(["{{", "}}"], "", $key);
    $key = preg_replace('/\s+/', '', $key);
    $key = trim($key);

    return xml_safe($vars[$key] ?? "");
  }, $xml);
}

$id = (int)($_GET["id"] ?? 0);

if ($id <= 0) {
  out_error(400, "Missing id");
}

$templatePath = __DIR__ . "/Incident-Record-Form template.docx";

if (!file_exists($templatePath)) {
  out_error(500, "DOCX template not found", $templatePath);
}

try {
  $scope = current_admin_scope($pdo, $AUTH_USER);

  $scopeSql = "";
  $params = [$id];

  if (!$scope["is_super_admin"]) {
    $scopeSql = "
      AND LOWER(TRIM(ir.province)) = LOWER(TRIM(?))
      AND LOWER(TRIM(ir.city_municipality)) = LOWER(TRIM(?))
    ";
    $params[] = $scope["province"];
    $params[] = $scope["city_municipality"];
  }

  $stmt = $pdo->prepare("
    SELECT
      ir.*,
      ps.station_name,
      ps.contact_landline,
      ps.contact_mobile,
      ps.contact_person,
      ps.emergency_contact
    FROM incident_reports ir
    LEFT JOIN police_stations ps ON ps.id = ir.assigned_station_id
    WHERE ir.id = ?
    $scopeSql
    LIMIT 1
  ");
  $stmt->execute($params);
  $incident = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$incident) {
    out_error(404, "Incident not found or outside your station city/municipality.");
  }

  $personsStmt = $pdo->prepare("
    SELECT *
    FROM incident_persons
    WHERE incident_id = ?
    ORDER BY id ASC
  ");
  $personsStmt->execute([$id]);
  $persons = $personsStmt->fetchAll(PDO::FETCH_ASSOC);

  $officersStmt = $pdo->prepare("
    SELECT *
    FROM incident_officers
    WHERE incident_id = ?
    ORDER BY id ASC
  ");
  $officersStmt->execute([$id]);
  $officers = $officersStmt->fetchAll(PDO::FETCH_ASSOC);

  /*
    Reporter fallback from users + user_profiles if REPORTING_PERSON
    was not manually added in incident_persons.
  */
  $reportingPerson = first_by_role($persons, "REPORTING_PERSON");

  if (!$reportingPerson && !empty($incident["reporter_user_id"])) {
    $userStmt = $pdo->prepare("
      SELECT
        u.firstname,
        u.lastname,
        u.email,
        up.mobile_number,
        up.address_text,
        up.barangay,
        up.city_municipality,
        up.province
      FROM users u
      LEFT JOIN user_profiles up ON up.user_id = u.id
      WHERE u.id = ?
      LIMIT 1
    ");
    $userStmt->execute([(int)$incident["reporter_user_id"]]);
    $u = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($u) {
      $reportingPerson = [
        "person_role" => "REPORTING_PERSON",
        "family_name" => $u["lastname"] ?? "",
        "first_name" => $u["firstname"] ?? "",
        "middle_name" => "",
        "email_address" => $u["email"] ?? "",
        "mobile_phone" => $u["mobile_number"] ?? "",
        "current_address" => $u["address_text"] ?? "",
        "current_barangay" => $u["barangay"] ?? "",
        "current_city" => $u["city_municipality"] ?? "",
        "current_province" => $u["province"] ?? ""
      ];
    }
  }

  $suspect = first_by_role($persons, "SUSPECT");
  $guardian = first_by_role($persons, "GUARDIAN");
  $victim = first_by_role($persons, "VICTIM");

  $adminOfficer =
    first_officer_by_role($officers, "ADMINISTERING_OFFICER")
    ?: first_officer_by_role($officers, "DUTY_INVESTIGATOR")
    ?: ($officers[0] ?? null);

  $deskOfficer =
    first_officer_by_role($officers, "DESK_OFFICER")
    ?: ($officers[0] ?? null);

  $investigator =
    first_officer_by_role($officers, "DUTY_INVESTIGATOR")
    ?: first_officer_by_role($officers, "ASSISTING_OFFICER")
    ?: ($officers[0] ?? null);

  $place = trim(
    ($incident["place_of_incident"] ?? "") . ", " .
    ($incident["sitio"] ?? "") . ", " .
    ($incident["barangay"] ?? "") . ", " .
    ($incident["city_municipality"] ?? "") . ", " .
    ($incident["province"] ?? "")
  );
  $place = preg_replace('/,\s*,/', ',', $place);
  $place = trim($place, " ,");

  $vars = [
  "id" => $incident["id"] ?? "",
  "incident_id" => $incident["id"] ?? "",
  "incident_code" => $incident["incident_code"] ?? "",
  "irf_entry_number" => $incident["irf_entry_number"] ?? "",
  "blotter_entry_number" => $incident["blotter_entry_number"] ?? "",
  "reporter_user_id" => $incident["reporter_user_id"] ?? "",
  "report_source" => $incident["report_source"] ?? "",
  "report_channel" => $incident["report_channel"] ?? "",
  "crime_type_id" => $incident["crime_type_id"] ?? "",
  "incident_type" => $incident["incident_type"] ?? "",
  "crime_category" => $incident["crime_category"] ?? "",
  "focus_crime_code" => $incident["focus_crime_code"] ?? "",
  "ciras_offense_code" => $incident["ciras_offense_code"] ?? "",
  "title" => $incident["title"] ?? "",
  "narrative" => $incident["narrative"] ?? "",
  "date_reported" => fmt_dt($incident["date_reported"] ?? ""),
  "date_incident_from" => fmt_dt($incident["date_incident_from"] ?? ""),
  "date_incident_to" => fmt_dt($incident["date_incident_to"] ?? ""),
  "place_of_incident" => $place,
  "sitio" => $incident["sitio"] ?? "",
  "barangay" => $incident["barangay"] ?? "",
  "city_municipality" => $incident["city_municipality"] ?? "",
  "province" => $incident["province"] ?? "",
  "region" => $incident["region"] ?? "",
  "assigned_station_id" => $incident["assigned_station_id"] ?? "",
  "lat" => $incident["lat"] ?? "",
  "lng" => $incident["lng"] ?? "",
  "accuracy_m" => $incident["accuracy_m"] ?? "",
  "geohash" => $incident["geohash"] ?? "",
  "location_type" => $incident["location_type"] ?? "",
  "is_hotspot_related" => yes_no($incident["is_hotspot_related"] ?? 0),
  "hotspot_id" => $incident["hotspot_id"] ?? "",
  "risk_status" => $incident["risk_status"] ?? "",
  "risk_distance_m" => $incident["risk_distance_m"] ?? "",
  "risk_radius_m" => $incident["risk_radius_m"] ?? "",
  "incident_phase" => $incident["incident_phase"] ?? "",
  "verification_status" => $incident["verification_status"] ?? "",
  "case_status" => $incident["case_status"] ?? "",
  "has_known_suspect" => yes_no($incident["has_known_suspect"] ?? 0),
  "suspect_count" => $incident["suspect_count"] ?? "",
  "victim_count" => $incident["victim_count"] ?? "",
  "witness_count" => $incident["witness_count"] ?? "",
  "property_loss_flag" => yes_no($incident["property_loss_flag"] ?? 0),
  "estimated_damage_value" => $incident["estimated_damage_value"] ?? "",
  "device_time" => fmt_dt($incident["device_time"] ?? ""),
  "created_at" => fmt_dt($incident["created_at"] ?? ""),
  "updated_at" => fmt_dt($incident["updated_at"] ?? ""),
  "reviewed_by" => $incident["reviewed_by"] ?? "",
  "reviewed_at" => fmt_dt($incident["reviewed_at"] ?? ""),
  "resolved_at" => fmt_dt($incident["resolved_at"] ?? ""),
  "admin_notes" => $incident["admin_notes"] ?? "",
  "severity_score" => $incident["severity_score"] ?? "",

  "station_name" => $incident["station_name"] ?? ($AUTH_USER["station_name"] ?? ""),
  "station_telephone" => $incident["contact_landline"] ?? "",
  "station_mobile" => $incident["contact_mobile"] ?? "",
  "station_chief" => $incident["contact_person"] ?? "",
  "station_emergency_contact" => $incident["emergency_contact"] ?? "",
  "investigator_on_case" => officer_full_name($investigator),
  "investigator_mobile_phone" => $investigator["mobile_phone"] ?? "",
  "desk_officer_name" => officer_full_name($deskOfficer),
  "administering_officer_name" => officer_full_name($adminOfficer)
];

  merge_prefixed_vars($vars, "*", person_vars($reportingPerson));
  merge_prefixed_vars($vars, "!", person_vars($suspect));
  merge_prefixed_vars($vars, "~", person_vars($guardian));
  merge_prefixed_vars($vars, "+", person_vars($victim));
  merge_prefixed_vars($vars, "#", officer_vars($adminOfficer));

  /*
    Template has one unprefixed {{hair_description}} placeholder.
    Based on your symbol guide, this belongs to SUSPECT data.
  */
  $vars["hair_description"] = $suspect["hair_description"] ?? "";

  $tmp = tempnam(sys_get_temp_dir(), "irf_template_");
  if (!$tmp) {
    throw new Exception("Unable to create temp file.");
  }

  if (!copy($templatePath, $tmp)) {
    throw new Exception("Unable to copy DOCX template.");
  }

  $zip = new ZipArchive();

  if ($zip->open($tmp) !== true) {
    throw new Exception("Unable to open DOCX template.");
  }

  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);

    /*
      Replace placeholders in the main document, headers, footers,
      footnotes, endnotes, and text-bearing Word XML files only.
    */
    if (
      preg_match('/^word\/.*\.xml$/', $name) &&
      !preg_match('/^word\/(styles|settings|numbering|fontTable|webSettings)\.xml$/', $name)
    ) {
      $xml = $zip->getFromName($name);
      if ($xml !== false && str_contains($xml, "{{")) {
        $xml = replace_placeholders_in_xml($xml, $vars);
        $zip->addFromString($name, $xml);
      }
    }
  }

  $zip->close();

  $safeNameSource = $incident["irf_entry_number"] ?: ($incident["incident_code"] ?: ("incident-" . $id));
  $safeName = preg_replace('/[^A-Za-z0-9_-]/', "_", (string)$safeNameSource);
  $filename = "IRF-" . $safeName . ".docx";

  while (ob_get_level()) {
    ob_end_clean();
  }

  header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
  header("Content-Disposition: attachment; filename=\"" . $filename . "\"");
  header("Content-Length: " . filesize($tmp));
  header("Cache-Control: no-store, no-cache, must-revalidate");
  header("Pragma: no-cache");

  readfile($tmp);
  unlink($tmp);
  exit;

} catch (Throwable $e) {
  if (isset($tmp) && $tmp && file_exists($tmp)) {
    @unlink($tmp);
  }

  out_error(500, "DOCX export failed", $e->getMessage());
}