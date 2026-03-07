<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/require_admin.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$days = isset($_GET["days"]) ? (int)$_GET["days"] : 30;
$days = max(1, min(365, $days));

try {
  $hotspots = get_computed_hotspots($pdo, $days);

  $predictions = array_map(function ($h) {
    $recommendedAction = "Monitor";
    if ($h["highlight_color"] === "red") {
      $recommendedAction = "Immediate patrol / alert";
    } elseif ($h["highlight_color"] === "green") {
      $recommendedAction = "Caution / monitor closely";
    }

    return [
      "id" => $h["id"],
      "name" => $h["name"],
      "lat" => $h["lat"],
      "lng" => $h["lng"],
      "radius_m" => $h["radius_m"],
      "incident_count" => $h["incident_count"],
      "panic_count" => $h["panic_count"],
      "panic_score" => $h["panic_score"],
      "score" => $h["score"],
      "highlight_color" => $h["highlight_color"],
      "risk_level" => $h["risk_level"],
      "recommended_action" => $recommendedAction,
      "last_detected_at" => $h["last_detected_at"],
    ];
  }, $hotspots);

  out(200, [
    "ok" => true,
    "days" => $days,
    "predictions" => $predictions
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}