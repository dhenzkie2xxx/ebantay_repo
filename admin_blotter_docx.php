<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/location_resolver.php";

function norm_text($v): ?string {
  $v = trim((string)($v ?? ""));
  $v = preg_replace('/\s+/', ' ', $v);
  return $v === "" ? null : $v;
}

function xml_escape($v): string {
  return htmlspecialchars((string)($v ?? ""), ENT_QUOTES | ENT_XML1, "UTF-8");
}

function fmt_dt($v): string {
  if (!$v) return "";
  $ts = strtotime($v);
  if ($ts === false) return (string)$v;
  return date("m/d/Y h:i A", $ts);
}

function current_admin_scope(PDO $pdo, array $AUTH_USER): array {
  $role = (string)($AUTH_USER["role"] ?? "");
  if ($role === "super_admin") {
    return ["is_super_admin" => true, "province" => null, "city_municipality" => null];
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

function p($text = "", $bold = false, $size = 22, $align = "left"): string {
  $jc = $align !== "left" ? "<w:jc w:val=\"{$align}\"/>" : "";
  $b = $bold ? "<w:b/>" : "";
  return "
    <w:p>
      <w:pPr>{$jc}</w:pPr>
      <w:r>
        <w:rPr>{$b}<w:sz w:val=\"{$size}\"/></w:rPr>
        <w:t xml:space=\"preserve\">" . xml_escape($text) . "</w:t>
      </w:r>
    </w:p>";
}

function cell($text, $bold = false, $width = 2400): string {
  $b = $bold ? "<w:b/>" : "";
  return "
    <w:tc>
      <w:tcPr><w:tcW w:w=\"{$width}\" w:type=\"dxa\"/></w:tcPr>
      <w:p>
        <w:r>
          <w:rPr>{$b}<w:sz w:val=\"20\"/></w:rPr>
          <w:t xml:space=\"preserve\">" . xml_escape($text) . "</w:t>
        </w:r>
      </w:p>
    </w:tc>";
}

function row($cells): string {
  return "<w:tr>" . implode("", $cells) . "</w:tr>";
}

function table($rows): string {
  return "
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w=\"0\" w:type=\"auto\"/>
        <w:tblBorders>
          <w:top w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
          <w:left w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
          <w:bottom w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
          <w:right w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
          <w:insideH w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
          <w:insideV w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"000000\"/>
        </w:tblBorders>
      </w:tblPr>
      " . implode("", $rows) . "
    </w:tbl>";
}

function person_name($p): string {
  if (!$p) return "";
  return trim(($p["first_name"] ?? "") . " " . ($p["middle_name"] ?? "") . " " . ($p["family_name"] ?? ""));
}

function first_by_role($persons, $role) {
  foreach ($persons as $p) {
    if (strtoupper((string)$p["person_role"]) === $role) return $p;
  }
  return null;
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo "Missing id";
  exit;
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
    SELECT ir.*
    FROM incident_reports ir
    WHERE ir.id = ?
    $scopeSql
    LIMIT 1
  ");
  $stmt->execute($params);
  $incident = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$incident) {
    http_response_code(404);
    echo "Incident not found or outside your station city/municipality.";
    exit;
  }

  $ps = $pdo->prepare("SELECT * FROM incident_persons WHERE incident_id = ? ORDER BY id ASC");
  $ps->execute([$id]);
  $persons = $ps->fetchAll(PDO::FETCH_ASSOC);

  $os = $pdo->prepare("SELECT * FROM incident_officers WHERE incident_id = ? ORDER BY id ASC");
  $os->execute([$id]);
  $officers = $os->fetchAll(PDO::FETCH_ASSOC);

  $station = null;
  if (!empty($incident["assigned_station_id"])) {
    $ss = $pdo->prepare("SELECT * FROM police_stations WHERE id = ? LIMIT 1");
    $ss->execute([(int)$incident["assigned_station_id"]]);
    $station = $ss->fetch(PDO::FETCH_ASSOC);
  }

  $reporting = first_by_role($persons, "REPORTING_PERSON") ?: ($persons[0] ?? null);
  $victim = first_by_role($persons, "VICTIM");
  $suspect = first_by_role($persons, "SUSPECT");
  $deskOfficer = first_by_role($officers, "DESK_OFFICER") ?: ($officers[0] ?? null);
  $investigator = first_by_role($officers, "DUTY_INVESTIGATOR") ?: ($officers[0] ?? null);
  $adminOfficer = first_by_role($officers, "ADMINISTERING_OFFICER") ?: ($officers[0] ?? null);

  $body = "";

  $body .= p("Philippine National Police", true, 24, "center");
  $body .= p("INCIDENT RECORD FORM", true, 28, "center");
  $body .= p("");

  $body .= table([
    row([cell("IRF ENTRY NUMBER:", true), cell($incident["irf_entry_number"] ?? "", false, 5000)]),
    row([cell("TYPE OF INCIDENT:", true), cell($incident["incident_type"] ?? "", false, 5000)]),
    row([cell("COPY FOR:", true), cell("Police Station / Reporting Person", false, 5000)]),
  ]);

  $body .= p("INSTRUCTIONS: Refer to PNP SOP on Recording of Incidents in the Police Blotter in filling up this form.", false, 18);
  $body .= p("");

  $body .= table([
    row([cell("DATE AND TIME REPORTED:", true), cell(fmt_dt($incident["date_reported"] ?? $incident["created_at"]), false, 5000)]),
    row([cell("DATE AND TIME OF INCIDENT:", true), cell(fmt_dt($incident["date_incident_from"] ?? ""), false, 5000)]),
    row([cell("PLACE OF INCIDENT:", true), cell(trim(($incident["place_of_incident"] ?? "") . ", " . ($incident["barangay"] ?? "") . ", " . ($incident["city_municipality"] ?? "") . ", " . ($incident["province"] ?? "")), false, 5000)]),
  ]);

  $body .= p("ITEM “A” - REPORTING PERSON", true, 24);
  $body .= table([
    row([cell("FAMILY NAME", true), cell($reporting["family_name"] ?? ""), cell("FIRST NAME", true), cell($reporting["first_name"] ?? "")]),
    row([cell("MIDDLE NAME", true), cell($reporting["middle_name"] ?? ""), cell("NICKNAME", true), cell($reporting["nickname"] ?? "")]),
    row([cell("SEX/GENDER", true), cell($reporting["sex_gender"] ?? ""), cell("CIVIL STATUS", true), cell($reporting["civil_status"] ?? "")]),
    row([cell("DATE OF BIRTH", true), cell($reporting["birth_date"] ?? ""), cell("AGE", true), cell($reporting["age"] ?? "")]),
    row([cell("MOBILE PHONE", true), cell($reporting["mobile_phone"] ?? ""), cell("EMAIL ADDRESS", true), cell($reporting["email_address"] ?? "")]),
    row([cell("CURRENT ADDRESS", true), cell($reporting["current_address"] ?? "", false, 5000), cell("BARANGAY", true), cell($reporting["current_barangay"] ?? "")]),
    row([cell("TOWN/CITY", true), cell($reporting["current_city"] ?? ""), cell("PROVINCE", true), cell($reporting["current_province"] ?? "")]),
    row([cell("OCCUPATION", true), cell($reporting["occupation"] ?? "", false, 5000)]),
  ]);

  $body .= p("ITEM “B” – SUSPECT’S DATA", true, 24);
  $body .= table([
    row([cell("FAMILY NAME", true), cell($suspect["family_name"] ?? ""), cell("FIRST NAME", true), cell($suspect["first_name"] ?? "")]),
    row([cell("MIDDLE NAME", true), cell($suspect["middle_name"] ?? ""), cell("NICKNAME", true), cell($suspect["nickname"] ?? "")]),
    row([cell("SEX/GENDER", true), cell($suspect["sex_gender"] ?? ""), cell("CIVIL STATUS", true), cell($suspect["civil_status"] ?? "")]),
    row([cell("DATE OF BIRTH", true), cell($suspect["birth_date"] ?? ""), cell("AGE", true), cell($suspect["age"] ?? "")]),
    row([cell("MOBILE PHONE", true), cell($suspect["mobile_phone"] ?? ""), cell("EMAIL ADDRESS", true), cell($suspect["email_address"] ?? "")]),
    row([cell("CURRENT ADDRESS", true), cell($suspect["current_address"] ?? "", false, 5000)]),
    row([cell("RELATION TO VICTIM", true), cell($suspect["relation_to_victim"] ?? ""), cell("SUSPECT STATUS", true), cell($suspect["suspect_status"] ?? "")]),
  ]);

  $body .= p("ITEM “C” – VICTIM’S DATA", true, 24);
  $body .= table([
    row([cell("FAMILY NAME", true), cell($victim["family_name"] ?? ""), cell("FIRST NAME", true), cell($victim["first_name"] ?? "")]),
    row([cell("MIDDLE NAME", true), cell($victim["middle_name"] ?? ""), cell("NICKNAME", true), cell($victim["nickname"] ?? "")]),
    row([cell("SEX/GENDER", true), cell($victim["sex_gender"] ?? ""), cell("CIVIL STATUS", true), cell($victim["civil_status"] ?? "")]),
    row([cell("DATE OF BIRTH", true), cell($victim["birth_date"] ?? ""), cell("AGE", true), cell($victim["age"] ?? "")]),
    row([cell("MOBILE PHONE", true), cell($victim["mobile_phone"] ?? ""), cell("EMAIL ADDRESS", true), cell($victim["email_address"] ?? "")]),
    row([cell("CURRENT ADDRESS", true), cell($victim["current_address"] ?? "", false, 5000)]),
  ]);

  $body .= p("ITEM “D” - NARRATIVE OF INCIDENT", true, 24);
  $body .= table([
    row([cell("TYPE OF INCIDENT", true), cell($incident["incident_type"] ?? "", false, 5000)]),
    row([cell("DATE/TIME OF INCIDENT", true), cell(fmt_dt($incident["date_incident_from"] ?? ""), false, 5000)]),
    row([cell("PLACE OF INCIDENT", true), cell(trim(($incident["place_of_incident"] ?? "") . ", " . ($incident["barangay"] ?? "") . ", " . ($incident["city_municipality"] ?? "") . ", " . ($incident["province"] ?? "")), false, 5000)]),
  ]);

  $body .= p("ENTER IN DETAIL THE NARRATIVE OF THE INCIDENT OR EVENT, ANSWERING THE WHO, WHAT, WHEN, WHERE, WHY AND HOW OF REPORTING.", true, 20);
  $body .= p($incident["narrative"] ?? "", false, 22);
  $body .= p("");
  $body .= p("I HEREBY CERTIFY TO THE CORRECTNESS OF THE FOREGOING TO THE BEST OF MY KNOWLEDGE AND BELIEF.", true, 20);

  $body .= table([
    row([cell("NAME OF REPORTING PERSON", true), cell(person_name($reporting), false, 5000)]),
    row([cell("SIGNATURE OF REPORTING PERSON", true), cell("", false, 5000)]),
    row([cell("NAME OF ADMINISTERING OFFICER", true), cell(person_name($adminOfficer), false, 5000)]),
    row([cell("SIGNATURE OF ADMINISTERING OFFICER", true), cell("", false, 5000)]),
    row([cell("RANK, NAME AND DESIGNATION OF POLICE OFFICER", true), cell(trim(($investigator["rank_title"] ?? "") . " " . ($investigator["full_name"] ?? "") . " - " . ($investigator["designation"] ?? "")), false, 5000)]),
    row([cell("INCIDENT RECORDED IN THE BLOTTER BY", true), cell(trim(($deskOfficer["rank_title"] ?? "") . " " . ($deskOfficer["full_name"] ?? "")), false, 5000)]),
    row([cell("BLOTTER ENTRY NR", true), cell($incident["blotter_entry_number"] ?? "", false, 5000)]),
  ]);

  $body .= p("REMINDER TO REPORTING PERSON", true, 22);
  $body .= p("Keep the copy of this Incident Record Form (IRF). An update of the progress of the investigation of the crime or incident that you reported will be given to you upon presentation of this IRF.", false, 18);

  $body .= table([
    row([cell("Name of Police Station", true), cell($station["station_name"] ?? ($AUTH_USER["station_name"] ?? ""), false, 5000)]),
    row([cell("Telephone", true), cell($station["contact_landline"] ?? "", false, 5000)]),
    row([cell("Investigator-on-Case", true), cell(person_name($investigator), false, 5000)]),
    row([cell("Mobile Phone", true), cell($investigator["mobile_phone"] ?? ($station["contact_mobile"] ?? ""), false, 5000)]),
    row([cell("Name of Chief/Head of Office", true), cell($station["contact_person"] ?? "", false, 5000)]),
    row([cell("Mobile Phone", true), cell($station["emergency_contact"] ?? "", false, 5000)]),
  ]);

  $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
  <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:body>
      ' . $body . '
      <w:sectPr>
        <w:pgSz w:w="12240" w:h="15840"/>
        <w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/>
      </w:sectPr>
    </w:body>
  </w:document>';

  $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
  <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  </Types>';

  $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
  <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  </Relationships>';

  $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
  <w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
      <w:name w:val="Normal"/>
      <w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="20"/></w:rPr>
    </w:style>
  </w:styles>';

  $tmp = tempnam(sys_get_temp_dir(), "irf_");
  $zip = new ZipArchive();

  if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    throw new Exception("Unable to create DOCX.");
  }

  $zip->addFromString("[Content_Types].xml", $contentTypes);
  $zip->addFromString("_rels/.rels", $rels);
  $zip->addFromString("word/document.xml", $documentXml);
  $zip->addFromString("word/styles.xml", $styles);
  $zip->close();

  $filename = "IRF-" . preg_replace('/[^A-Za-z0-9_-]/', "_", (string)($incident["irf_entry_number"] ?: $incident["incident_code"])) . ".docx";

  header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
  header("Content-Disposition: attachment; filename=\"$filename\"");
  header("Content-Length: " . filesize($tmp));
  readfile($tmp);
  unlink($tmp);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode([
    "ok" => false,
    "message" => "DOCX export failed",
    "debug" => $e->getMessage()
  ]);
}