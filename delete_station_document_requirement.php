<?php
require_once __DIR__ . "/require_super_admin.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
  auth_out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$data = station_json_input();
$id = (int)($data["id"] ?? $_GET["id"] ?? 0);

if ($id <= 0) {
  auth_out(400, [
    "ok" => false,
    "message" => "Invalid requirement ID."
  ]);
}

try {
  $stmt = $pdo->prepare("
    SELECT id, is_system
    FROM station_document_requirements
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    auth_out(404, [
      "ok" => false,
      "message" => "Requirement not found."
    ]);
  }

  if ((int)$row["is_system"] === 1) {
    auth_out(400, [
      "ok" => false,
      "message" => "Default requirements cannot be deleted."
    ]);
  }

  $upd = $pdo->prepare("
    UPDATE station_document_requirements
    SET active = 0
    WHERE id = ?
  ");
  $upd->execute([$id]);

  auth_out(200, [
    "ok" => true,
    "message" => "Requirement deactivated successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Failed to deactivate station document requirement."
  ]);
}