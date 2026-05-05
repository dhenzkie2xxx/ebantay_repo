<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/audit_log_helper.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$method = $_SERVER["REQUEST_METHOD"];
$token = bearer_token();

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

if ($token === "") {
  $token = trim($data["token"] ?? $_GET["token"] ?? "");
}

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

try {
  $admin = auth_get_user_by_token($pdo, $token);

  if (!$admin) {
    out(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($admin)) {
    out(401, ["ok" => false, "message" => "Token expired"]);
  }

  $gate = auth_admin_station_gate($admin);
  if ($gate) {
    out($gate["code"], $gate["payload"]);
  }

  if ($admin["role"] !== "admin") {
    out(403, [
      "ok" => false,
      "message" => "Only Station Admin can manage announcements."
    ]);
  }

  $stationId = (int)$admin["station_id"];

  if ($method === "GET") {
    $stmt = $pdo->prepare("
      SELECT
        id,
        station_id,
        created_by,
        title,
        message,
        region,
        province,
        city_municipality,
        status,
        priority,
        created_at,
        updated_at
      FROM community_announcements
      WHERE station_id = ?
      ORDER BY created_at DESC
      LIMIT 200
    ");

    $stmt->execute([$stationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    out(200, [
      "ok" => true,
      "announcements" => array_map(function ($r) {
        return [
          "id" => (int)$r["id"],
          "station_id" => (int)$r["station_id"],
          "created_by" => (int)$r["created_by"],
          "title" => $r["title"],
          "message" => $r["message"],
          "region" => $r["region"],
          "province" => $r["province"],
          "city_municipality" => $r["city_municipality"],
          "status" => $r["status"],
          "priority" => $r["priority"],
          "created_at" => $r["created_at"],
          "updated_at" => $r["updated_at"]
        ];
      }, $rows)
    ]);
  }

  if ($method === "POST") {
    $title = trim((string)($data["title"] ?? ""));
    $message = trim((string)($data["message"] ?? ""));
    $priority = trim((string)($data["priority"] ?? "normal"));
    $status = trim((string)($data["status"] ?? "active"));

    if ($title === "" || mb_strlen($title) < 3) {
      out(400, ["ok" => false, "message" => "Announcement title is required."]);
    }

    if ($message === "" || mb_strlen($message) < 5) {
      out(400, ["ok" => false, "message" => "Announcement message is required."]);
    }

    if (!in_array($priority, ["normal", "important", "urgent"], true)) {
      $priority = "normal";
    }

    if (!in_array($status, ["active", "inactive"], true)) {
      $status = "active";
    }

    $region = $admin["station_region"] ?? null;
    $province = $admin["station_province"] ?? null;
    $cityMunicipality = $admin["station_city_municipality"] ?? null;

    $stmt = $pdo->prepare("
      INSERT INTO community_announcements (
        station_id,
        created_by,
        title,
        message,
        region,
        province,
        city_municipality,
        status,
        priority
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
      $stationId,
      (int)$admin["id"],
      $title,
      $message,
      $region,
      $province,
      $cityMunicipality,
      $status,
      $priority
    ]);

    $announcementId = (int)$pdo->lastInsertId();

    $newValues = [
      "id" => $announcementId,
      "station_id" => $stationId,
      "created_by" => (int)$admin["id"],
      "title" => $title,
      "message" => $message,
      "region" => $region,
      "province" => $province,
      "city_municipality" => $cityMunicipality,
      "status" => $status,
      "priority" => $priority
    ];

    write_audit_log(
      $pdo,
      $admin,
      "ANNOUNCEMENT_CREATED",
      "community_announcement",
      $announcementId,
      "Station Admin created a community announcement.",
      [
        "module" => "announcements",
        "old_values" => null,
        "new_values" => $newValues
      ]
    );

    out(200, [
      "ok" => true,
      "message" => "Announcement created successfully.",
      "announcement_id" => $announcementId
    ]);
  }

  out(405, ["ok" => false, "message" => "Method not allowed"]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}