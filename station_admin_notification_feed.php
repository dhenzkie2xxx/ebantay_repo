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
      "message" => "Only Station Admin can view notification feed."
    ]);
  }

  $stmt = $pdo->prepare("
    SELECT
      id,
      user_id,
      type,
      title,
      message,
      incident_id,
      severity,
      is_read,
      created_at
    FROM notification_alerts
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 100
  ");

  $stmt->execute([(int)$admin["id"]]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $notifications = array_map(function ($r) {
    return [
      "id" => (int)$r["id"],
      "type" => $r["type"],
      "title" => $r["title"],
      "message" => $r["message"],
      "incident_id" => $r["incident_id"] !== null ? (int)$r["incident_id"] : null,
      "severity" => $r["severity"],
      "is_read" => (int)$r["is_read"] === 1,
      "created_at" => $r["created_at"]
    ];
  }, $rows);

  $unreadCount = 0;
  foreach ($notifications as $n) {
    if (!$n["is_read"]) {
      $unreadCount++;
    }
  }

  out(200, [
    "ok" => true,
    "count" => count($notifications),
    "unread_count" => $unreadCount,
    "notifications" => $notifications
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}