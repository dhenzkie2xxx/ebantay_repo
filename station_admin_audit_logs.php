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
      "message" => "Only Station Admin can view audit logs."
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT
      al.*,
      actor.firstname AS actor_firstname,
      actor.lastname AS actor_lastname,
      target.firstname AS target_firstname,
      target.lastname AS target_lastname
    FROM audit_logs al
    LEFT JOIN users actor ON actor.id = al.actor_user_id
    LEFT JOIN users target ON target.id = al.target_user_id
    WHERE al.station_id = ?
    ORDER BY al.created_at DESC
    LIMIT 300
  ");

  $stmt->execute([(int)$admin["station_id"]]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $logs = array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "station_id" => $r["station_id"] !== null ? (int)$r["station_id"] : null,
      "actor_user_id" => (int)$r["actor_user_id"],
      "actor_name" => trim(($r["actor_firstname"] ?? "") . " " . ($r["actor_lastname"] ?? "")),
      "actor_role" => $r["actor_role"],

      "action_type" => $r["action_type"],
      "entity_type" => $r["entity_type"],
      "entity_id" => $r["entity_id"] !== null ? (int)$r["entity_id"] : null,

      "description" => $r["description"],

      "related_assignment_id" => $r["related_assignment_id"] !== null ? (int)$r["related_assignment_id"] : null,
      "related_incident_id" => $r["related_incident_id"] !== null ? (int)$r["related_incident_id"] : null,
      "related_panic_id" => $r["related_panic_id"] !== null ? (int)$r["related_panic_id"] : null,

      "target_user_id" => $r["target_user_id"] !== null ? (int)$r["target_user_id"] : null,
      "target_name" => trim(($r["target_firstname"] ?? "") . " " . ($r["target_lastname"] ?? "")),

      "ip_address" => $r["ip_address"],
      "user_agent" => $r["user_agent"],
      "created_at" => $r["created_at"]
    ];
  }, $rows);

  out(200, [
    "ok" => true,
    "count" => count($logs),
    "logs" => $logs
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}