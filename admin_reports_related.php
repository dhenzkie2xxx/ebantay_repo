<?php
require_once __DIR__ . "/require_admin.php";

header("Content-Type: application/json; charset=UTF-8");

$id = (int)($_GET["id"] ?? 0);

if ($id <= 0) {
  http_response_code(400);
  echo json_encode([
    "ok" => false,
    "message" => "Missing id"
  ]);
  exit;
}

/*
|--------------------------------------------------------------------------
| Get assigned station scope
|--------------------------------------------------------------------------
*/
$stationStmt = $pdo->prepare("
  SELECT
    ps.province,
    ps.city_municipality
  FROM incident_reports ir
  LEFT JOIN police_stations ps
    ON ps.id = ir.assigned_station_id
  WHERE ir.id = ?
  LIMIT 1
");
$stationStmt->execute([$id]);

$stationScope = $stationStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$stationProvince = strtolower(trim((string)($stationScope["province"] ?? "")));
$stationCity = strtolower(trim((string)($stationScope["city_municipality"] ?? "")));

/*
|--------------------------------------------------------------------------
| Persons
|--------------------------------------------------------------------------
*/
$personsStmt = $pdo->prepare("
  SELECT *
  FROM incident_persons
  WHERE incident_id = ?
  ORDER BY id ASC
");
$personsStmt->execute([$id]);

$persons = $personsStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Properties
|--------------------------------------------------------------------------
*/
$propertiesStmt = $pdo->prepare("
  SELECT *
  FROM incident_properties
  WHERE incident_id = ?
  ORDER BY id ASC
");
$propertiesStmt->execute([$id]);

$properties = $propertiesStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Officers
|--------------------------------------------------------------------------
*/
$officersStmt = $pdo->prepare("
  SELECT *
  FROM incident_officers
  WHERE incident_id = ?
  ORDER BY id ASC
");
$officersStmt->execute([$id]);

$officers = $officersStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "ok" => true,

  "persons" => array_map(function($r) use ($stationProvince, $stationCity) {

    $personProvince = strtolower(trim((string)($r["current_province"] ?? "")));
    $personCity = strtolower(trim((string)($r["current_city"] ?? "")));

    $registeredWithinStation =
      $stationProvince !== "" &&
      $stationCity !== "" &&
      $personProvince !== "" &&
      $personCity !== "" &&
      $stationProvince === $personProvince &&
      $stationCity === $personCity;

    return [
      "id" => (int)$r["id"],

      "person_role" => $r["person_role"],

      "family_name" => $r["family_name"],
      "first_name" => $r["first_name"],
      "middle_name" => $r["middle_name"],
      "nickname" => $r["nickname"],

      "citizenship" => $r["citizenship"],

      "sex_gender" => $r["sex_gender"],
      "civil_status" => $r["civil_status"],

      "birth_date" => $r["birth_date"],
      "age" => $r["age"] !== null ? (int)$r["age"] : null,

      "place_of_birth" => $r["place_of_birth"],

      "home_phone" => $r["home_phone"],
      "mobile_phone" => $r["mobile_phone"],
      "email_address" => $r["email_address"],

      "current_address" => $r["current_address"],
      "current_sitio" => $r["current_sitio"],
      "current_barangay" => $r["current_barangay"],
      "current_city" => $r["current_city"],
      "current_province" => $r["current_province"],

      "occupation" => $r["occupation"],

      "relation_to_victim" => $r["relation_to_victim"],

      "guardian_name" => $r["guardian_name"],
      "guardian_address" => $r["guardian_address"],
      "guardian_mobile_phone" => $r["guardian_mobile_phone"],

      "suspect_status" => $r["suspect_status"],

      /*
      |--------------------------------------------------------------------------
      | Reporter registration indicator
      |--------------------------------------------------------------------------
      */
      "registered_within_station" => $registeredWithinStation,

      "registration_label" => $registeredWithinStation
        ? "Registered within the station"
        : "Not registered within the station"
    ];

  }, $persons),

  "properties" => array_map(function($r) {

    return [
      "id" => (int)$r["id"],

      "property_role" => $r["property_role"],
      "property_type" => $r["property_type"],

      "description" => $r["description"],

      "quantity" => (int)$r["quantity"],

      "estimated_value" => $r["estimated_value"] !== null
        ? (float)$r["estimated_value"]
        : null,

      "recovered_flag" => (int)$r["recovered_flag"],

      "serial_number" => $r["serial_number"],
      "plate_number" => $r["plate_number"]
    ];

  }, $properties),

  "officers" => array_map(function($r) {

    return [
      "id" => (int)$r["id"],

      "officer_role" => $r["officer_role"],

      "rank_title" => $r["rank_title"],
      "full_name" => $r["full_name"],

      "designation" => $r["designation"],

      "police_station" => $r["police_station"],

      "mobile_phone" => $r["mobile_phone"]
    ];

  }, $officers)
]);