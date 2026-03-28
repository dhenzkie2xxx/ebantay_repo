<?php
require_once __DIR__ . "/require_admin_account.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$data = station_json_input();
$documentId = (int)($data["document_id"] ?? $_GET["document_id"] ?? 0);

if ($documentId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid document ID."]);
}

try {
  $stmt = $pdo->prepare("
    SELECT
      d.id,
      d.station_id,
      ps.verification_status
    FROM police_station_documents d
    INNER JOIN police_stations ps ON ps.id = d.station_id
    WHERE d.id = ?
      AND ps.created_by = ?
    LIMIT 1
  ");
  $stmt->execute([$documentId, $AUTH_USER["id"]]);
  $doc = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$doc) {
    auth_out(404, ["ok" => false, "message" => "Document not found."]);
  }

  $status = (string)($doc["verification_status"] ?? "");

  if (!in_array($status, station_can_edit_statuses(), true)) {
    auth_out(403, [
      "ok" => false,
      "message" => "Document cannot be deleted in the current station status."
    ]);
  }

  $del = $pdo->prepare("
    DELETE FROM police_station_documents
    WHERE id = ?
      AND station_id = ?
    LIMIT 1
  ");
  $del->execute([
    $documentId,
    (int)$doc["station_id"]
  ]);

  auth_out(200, [
    "ok" => true,
    "message" => "Document deleted successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error.",
    "error" => $e->getMessage()
  ]);
}