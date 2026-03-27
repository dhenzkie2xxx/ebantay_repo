<?php 
require_once __DIR__ . "/require_admin_account.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
  auth_out(405, ["ok" => false, "message" => "Method not allowed"]);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$documentId = (int)($data["document_id"] ?? $_GET["document_id"] ?? 0);

if ($documentId <= 0) {
  auth_out(400, ["ok" => false, "message" => "Invalid document ID."]);
}

try {
  // ✅ FIX: removed file_path (BLOB system)
  $stmt = $pdo->prepare("
    SELECT
      d.id,
      ps.id AS station_id,
      ps.verification_status
    FROM police_station_documents d
    JOIN police_stations ps ON ps.id = d.station_id
    WHERE d.id = ?
      AND ps.created_by = ?
    LIMIT 1
  ");
  $stmt->execute([$documentId, $AUTH_USER["id"]]);
  $doc = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$doc) {
    auth_out(404, ["ok" => false, "message" => "Document not found."]);
  }

  // Prevent deletion if already approved
  if (!in_array($doc["verification_status"], ["draft", "pending", "rejected", "resubmission_required"], true)) {
    auth_out(403, [
      "ok" => false,
      "message" => "Document cannot be deleted in the current station status."
    ]);
  }

  // ✅ Delete record only (no file unlink anymore)
  $del = $pdo->prepare("DELETE FROM police_station_documents WHERE id = ?");
  $del->execute([$documentId]);

  auth_out(200, [
    "ok" => true,
    "message" => "Document deleted successfully."
  ]);

} catch (Throwable $e) {
  // 🔥 VERY IMPORTANT for debugging
  auth_out(500, [
    "ok" => false,
    "message" => "Server error.",
    "error" => $e->getMessage() // remove in production later
  ]);
}