<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
if ($token === "") {
  $token = trim($_GET["token"] ?? "");
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
      "message" => "Only Station Admin can view assignment queue."
    ]);
  }

  $stationId = (int)$admin["station_id"];

  $stmt = $pdo->prepare("
    SELECT
      ra.id AS assignment_id,
      ra.source_type,
      ra.source_id,
      ra.assigned_user_id,
      ra.assignment_role,
      ra.status AS assignment_status,
      ra.authorization_status,
      ra.detected_distance_m,
      ra.backup_requested,
      ra.backup_requested_at,
      ra.backup_admin_response,
      ra.backup_response_notes,
      ra.backup_responded_by,
      ra.backup_responded_at,
      ra.outcome,
      ra.outcome_at,
      ra.notes,
      ra.assigned_at,
      ra.proceed_requested_at,
      ra.authorized_at,

      u.firstname AS police_firstname,
      u.lastname AS police_lastname,
      u.username AS police_username,
      u.duty_status,
      u.last_seen_at,

      ir.incident_code,
      ir.title AS incident_title,
      ir.incident_type,
      ir.lat AS incident_lat,
      ir.lng AS incident_lng,
      ir.barangay AS incident_barangay,
      ir.city_municipality AS incident_city,
      ir.province AS incident_province,
      ir.verification_status,
      ir.incident_phase,
      ir.created_at AS incident_created_at,

      p.level AS panic_level,
      p.lat AS panic_lat,
      p.lng AS panic_lng,
      p.barangay AS panic_barangay,
      p.city_municipality AS panic_city,
      p.province AS panic_province,
      p.status AS panic_status,
      p.created_at AS panic_created_at

    FROM responder_assignments ra

    INNER JOIN users u
      ON u.id = ra.assigned_user_id

    LEFT JOIN incident_reports ir
      ON ra.source_type = 'incident'
     AND ir.id = ra.source_id

    LEFT JOIN panic_requests p
      ON ra.source_type = 'panic'
     AND p.id = ra.source_id

    WHERE ra.assigned_station_id = ?
      AND ra.status <> 'cancelled'

    ORDER BY
      FIELD(ra.authorization_status,
        'requested_to_proceed',
        'detected',
        'go_signal_sent',
        'approved_to_proceed',
        'denied',
        'cancelled'
      ),
      ra.assigned_at DESC

    LIMIT 200
  ");

  $stmt->execute([$stationId]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $queue = array_map(function($r) {
    $isIncident = $r["source_type"] === "incident";

    return [
      "assignment_id" => (int)$r["assignment_id"],
      "source_type" => $r["source_type"],
      "source_id" => (int)$r["source_id"],
      "assigned_user_id" => (int)$r["assigned_user_id"],
      "police_name" => trim($r["police_firstname"] . " " . $r["police_lastname"]),
      "police_username" => $r["police_username"],
      "duty_status" => $r["duty_status"],
      "last_seen_at" => $r["last_seen_at"],

      "assignment_role" => $r["assignment_role"],
      "assignment_status" => $r["assignment_status"],
      "authorization_status" => $r["authorization_status"],
      "needs_admin_action" => $r["authorization_status"] === "requested_to_proceed",
      "detected_distance_m" => $r["detected_distance_m"] !== null ? (int)$r["detected_distance_m"] : null,

      "backup_requested" => (int)$r["backup_requested"] === 1,
      "backup_requested_at" => $r["backup_requested_at"],
      "backup_admin_response" => $r["backup_admin_response"] ?? "pending",
      "backup_response_notes" => $r["backup_response_notes"],
      "backup_responded_by" => $r["backup_responded_by"] !== null ? (int)$r["backup_responded_by"] : null,
      "backup_responded_at" => $r["backup_responded_at"],

      "outcome" => $r["outcome"],
      "outcome_at" => $r["outcome_at"],
      "notes" => $r["notes"],
      "assigned_at" => $r["assigned_at"],
      "proceed_requested_at" => $r["proceed_requested_at"],
      "authorized_at" => $r["authorized_at"],

      "report" => $isIncident ? [
        "incident_code" => $r["incident_code"],
        "title" => $r["incident_title"],
        "incident_type" => $r["incident_type"],
        "lat" => $r["incident_lat"] !== null ? (float)$r["incident_lat"] : null,
        "lng" => $r["incident_lng"] !== null ? (float)$r["incident_lng"] : null,
        "barangay" => $r["incident_barangay"],
        "city_municipality" => $r["incident_city"],
        "province" => $r["incident_province"],
        "verification_status" => $r["verification_status"],
        "incident_phase" => $r["incident_phase"],
        "created_at" => $r["incident_created_at"]
      ] : [
        "level" => $r["panic_level"],
        "lat" => $r["panic_lat"] !== null ? (float)$r["panic_lat"] : null,
        "lng" => $r["panic_lng"] !== null ? (float)$r["panic_lng"] : null,
        "barangay" => $r["panic_barangay"],
        "city_municipality" => $r["panic_city"],
        "province" => $r["panic_province"],
        "status" => $r["panic_status"],
        "created_at" => $r["panic_created_at"]
      ]
    ];
  }, $rows);

  out(200, [
    "ok" => true,
    "count" => count($queue),
    "queue" => $queue
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}