<?php
require_once __DIR__ . "/require_admin_account.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.station_code,
      ps.station_name,
      ps.station_type,
      ps.region,
      ps.province,
      ps.city_municipality,
      ps.barangay,
      ps.sitio,
      ps.street_address,
      ps.full_address,
      ps.lat,
      ps.lng,
      ps.accuracy_m,
      ps.contact_person,
      ps.contact_position,
      ps.contact_mobile,
      ps.contact_landline,
      ps.contact_email,
      ps.operating_hours,
      ps.emergency_contact,
      ps.verification_status,
      ps.is_active,
      ps.submitted_at,
      ps.reviewed_at,
      ps.rejection_reason,
      ps.approved_at,
      ps.created_at,
      ps.updated_at
    FROM police_stations ps
    WHERE ps.created_by = ?
    LIMIT 1
  ");
  $stmt->execute([$AUTH_USER["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    auth_out(200, [
      "ok" => true,
      "station" => null
    ]);
  }

  auth_out(200, [
    "ok" => true,
    "station" => [
      "id" => (int)$row["id"],
      "station_code" => $row["station_code"],
      "station_name" => $row["station_name"],
      "station_type" => $row["station_type"],
      "region" => $row["region"],
      "province" => $row["province"],
      "city_municipality" => $row["city_municipality"],
      "barangay" => $row["barangay"],
      "sitio" => $row["sitio"],
      "street_address" => $row["street_address"],
      "full_address" => $row["full_address"],
      "lat" => (float)$row["lat"],
      "lng" => (float)$row["lng"],
      "accuracy_m" => $row["accuracy_m"] !== null ? (int)$row["accuracy_m"] : null,
      "contact_person" => $row["contact_person"],
      "contact_position" => $row["contact_position"],
      "contact_mobile" => $row["contact_mobile"],
      "contact_landline" => $row["contact_landline"],
      "contact_email" => $row["contact_email"],
      "operating_hours" => $row["operating_hours"],
      "emergency_contact" => $row["emergency_contact"],
      "verification_status" => $row["verification_status"],
      "is_active" => (int)$row["is_active"],
      "submitted_at" => $row["submitted_at"],
      "reviewed_at" => $row["reviewed_at"],
      "rejection_reason" => $row["rejection_reason"],
      "approved_at" => $row["approved_at"],
      "created_at" => $row["created_at"],
      "updated_at" => $row["updated_at"]
    ]
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}