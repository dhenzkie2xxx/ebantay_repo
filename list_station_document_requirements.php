<?php
require_once __DIR__ . "/require_super_admin.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  auth_out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

try {
  $stmt = $pdo->query("
    SELECT
      id,
      requirement_code,
      requirement_name,
      is_required,
      is_system,
      active,
      created_at,
      updated_at
    FROM station_document_requirements
    ORDER BY is_system DESC, id ASC
  ");

  $items = [];

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $items[] = [
      "id" => (int)$row["id"],
      "requirement_code" => $row["requirement_code"],
      "requirement_name" => $row["requirement_name"],
      "is_required" => (int)$row["is_required"],
      "is_system" => (int)$row["is_system"],
      "active" => (int)$row["active"],
      "created_at" => $row["created_at"],
      "updated_at" => $row["updated_at"]
    ];
  }

  auth_out(200, [
    "ok" => true,
    "items" => $items
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Failed to load station document requirements."
  ]);
}