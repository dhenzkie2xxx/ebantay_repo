<?php
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$hotspotId = (int)($data["hotspot_id"] ?? 0);
$incidentId = isset($data["incident_id"]) ? (int)$data["incident_id"] : null;
$title = trim((string)($data["title"] ?? ""));
$message = trim((string)($data["message"] ?? ""));
$severity = strtoupper(trim((string)($data["severity"] ?? "HIGH")));

if ($hotspotId <= 0 || $title === "" || $message === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "Invalid payload"]);
  exit;
}

if (!in_array($severity, ["LOW", "MEDIUM", "HIGH"], true)) {
  $severity = "HIGH";
}

try {
  $result = create_hotspot_broadcast_alerts(
    $pdo,
    $hotspotId,
    $incidentId,
    $title,
    $message,
    $severity
  );

  echo json_encode([
    "ok" => true,
    "message" => "Broadcast queued",
    "created_count" => $result["created"],
    "targets" => $result["targets"]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}