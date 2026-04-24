<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function json_input(): array {
  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $data = json_input();
  $id = isset($data["id"]) && is_numeric($data["id"]) ? (int)$data["id"] : 0;

  if ($id <= 0) {
    out(422, ["ok" => false, "message" => "Crime type ID is required"]);
  }

  $stmt = $pdo->prepare("
    SELECT id, crime_name
    FROM crime_types
    WHERE id = ?
      AND is_active = 1
    LIMIT 1
  ");
  $stmt->execute([$id]);
  $crimeType = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$crimeType) {
    out(404, ["ok" => false, "message" => "Crime type not found"]);
  }

  $deleteStmt = $pdo->prepare("
    UPDATE crime_types
    SET is_active = 0
    WHERE id = ?
    LIMIT 1
  ");
  $deleteStmt->execute([$id]);

  out(200, [
    "ok" => true,
    "message" => "Crime type removed successfully",
    "id" => $id
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}