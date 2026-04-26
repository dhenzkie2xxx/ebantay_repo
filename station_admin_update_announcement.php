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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$token = bearer_token();
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  out(400, ["ok" => false, "message" => "Invalid JSON body"]);
}

if ($token === "") {
  $token = trim($data["token"] ?? "");
}

$id = $data["id"] ?? null;
$title = trim((string)($data["title"] ?? ""));
$message = trim((string)($data["message"] ?? ""));
$priority = trim((string)($data["priority"] ?? "normal"));
$status = trim((string)($data["status"] ?? "active"));

if ($token === "") {
  out(401, ["ok" => false, "message" => "Missing token"]);
}

if (!is_numeric($id) || (int)$id <= 0) {
  out(400, ["ok" => false, "message" => "Invalid announcement ID"]);
}

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
      "message" => "Only Station Admin can update announcements."
    ]);
  }

  $stationId = (int)$admin["station_id"];
  $announcementId = (int)$id;

  $check = $pdo->prepare("
    SELECT id
    FROM community_announcements
    WHERE id = ?
      AND station_id = ?
    LIMIT 1
  ");
  $check->execute([$announcementId, $stationId]);

  if (!$check->fetch(PDO::FETCH_ASSOC)) {
    out(404, [
      "ok" => false,
      "message" => "Announcement not found under your station."
    ]);
  }

  $stmt = $pdo->prepare("
    UPDATE community_announcements
    SET
      title = ?,
      message = ?,
      priority = ?,
      status = ?,
      updated_at = NOW()
    WHERE id = ?
      AND station_id = ?
  ");

  $stmt->execute([
    $title,
    $message,
    $priority,
    $status,
    $announcementId,
    $stationId
  ]);

  write_audit_log(
    $pdo,
    $admin,
    "ANNOUNCEMENT_UPDATED",
    "community_announcement",
    $announcementId,
    "Station Admin updated a community announcement.",
    []
  );

  out(200, [
    "ok" => true,
    "message" => "Announcement updated successfully.",
    "announcement_id" => $announcementId
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => $e->getMessage()
  ]);
}