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

  return [
    "family_name" => $p["family_name"] ?? "",
    "first_name" => $p["first_name"] ?? "",
    "middle_name" => $p["middle_name"] ?? "",
    "qualifier" => $p["qualifier"] ?? "",
    "nickname" => $p["nickname"] ?? "",
    "citizenship" => $p["citizenship"] ?? "",
    "sex_gender" => $p["sex_gender"] ?? "",
    "civil_status" => $p["civil_status"] ?? "",
    "birth_date" => $p["birth_date"] ?? "",
    "age" => $p["age"] ?? "",
    "place_of_birth" => $p["place_of_birth"] ?? "",
    "home_phone" => $p["home_phone"] ?? "",
    "mobile_phone" => $p["mobile_phone"] ?? "",
    "mobile_number" => $p["mobile_phone"] ?? "",
    "email_address" => $p["email_address"] ?? "",
    "current_text" => $p["current_address"] ?? "",
    "current_address" => $p["current_address"] ?? "",
    "current_sitio" => $p["current_sitio"] ?? "",
    "current_barangay" => $p["current_barangay"] ?? "",
    "current_city" => $p["current_city"] ?? "",
    "current_province" => $p["current_province"] ?? "",
    "other_address" => $p["other_address"] ?? "",
    "other_sitio" => $p["other_sitio"] ?? "",
    "other_barangay" => $p["other_barangay"] ?? "",
    "other_city" => $p["other_city"] ?? "",
    "other_province" => $p["other_province"] ?? "",
    "educational_attainment" => $p["educational_attainment"] ?? "",
    "occupation" => $p["occupation"] ?? "",
    "work_address" => $p["work_address"] ?? "",
    "relation_to_victim" => $p["relation_to_victim"] ?? "",
    "rank_title" => $p["rank_title"] ?? "",
    "unit_assignment" => $p["unit_assignment"] ?? "",
    "group_affiliation" => $p["group_affiliation"] ?? "",
    "has_previous_criminal_record" => yes_no($p["has_previous_criminal_record"] ?? 0),
    "previous_case_status" => $p["previous_case_status"] ?? "",
    "height_cm" => $p["height_cm"] ?? "",
    "weight_kg" => $p["weight_kg"] ?? "",
    "built" => $p["built"] ?? "",
    "eye_color" => $p["eye_color"] ?? "",
    "eye_description" => $p["eye_description"] ?? "",
    "hair_color" => $p["hair_color"] ?? "",
    "hair_description" => $p["hair_description"] ?? "",
    "under_influence" => $p["under_influence"] ?? "",
    "under_influence_notes" => $p["under_influence_notes"] ?? "",
    "guardian_name" => $p["guardian_name"] ?? "",
    "guardian_address" => $p["guardian_address"] ?? "",
    "guardian_home_phone" => $p["guardian_home_phone"] ?? "",
    "guardian_mobile_phone" => $p["guardian_mobile_phone"] ?? "",
    "full_name" => person_full_name($p)
  ];
}

function officer_vars(?array $o): array {
  $o = $o ?: [];

  return [
    "officer_role" => $o["officer_role"] ?? "",
    "rank_title" => $o["rank_title"] ?? "",
    "full_name" => $o["full_name"] ?? "",
    "name_with_rank" => officer_full_name($o),
    "designation" => $o["designation"] ?? "",
    "police_station" => $o["police_station"] ?? "",
    "mobile_phone" => $o["mobile_phone"] ?? ""
  ];
}

function merge_prefixed_vars(array &$vars, string $prefix, array $data): void {
  foreach ($data as $k => $v) {
    $vars[$prefix . $k] = $v;
  }
}

function replace_placeholders_in_xml(string $xml, array $vars): string {
  return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/u', function($m) use ($vars) {
    $key = trim($m[1]);
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
    "incident_code" => $incident["incident_code"] ?? "",
    "irf_entry_number" => $incident["irf_entry_number"] ?? "",
    "blotter_entry_number" => $incident["blotter_entry_number"] ?? "",
    "incident_type" => $incident["incident_type"] ?? "",
    "crime_category" => $incident["crime_category"] ?? "",
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
    "case_status" => $incident["case_status"] ?? "",
    "verification_status" => $incident["verification_status"] ?? "",
    "incident_phase" => $incident["incident_phase"] ?? "",
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