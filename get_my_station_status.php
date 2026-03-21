<?php
require_once __DIR__ . "/require_admin_account.php";

header("Content-Type: application/json; charset=UTF-8");

try {
  $stmt = $pdo->prepare("
    SELECT
      u.id AS user_id,
      u.account_status,
      u.valid,
      u.rejected_reason AS user_rejected_reason,
      ps.id AS station_id,
      ps.station_name,
      ps.verification_status,
      ps.submitted_at,
      ps.reviewed_at,
      ps.rejection_reason,
      ps.approved_at,
      (
        SELECT COUNT(*)
        FROM police_station_documents d
        WHERE d.station_id = ps.id
          AND d.is_current = 1
      ) AS document_count
    FROM users u
    LEFT JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stmt->execute([$AUTH_USER["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    auth_out(404, ["ok" => false, "message" => "Account not found."]);
  }

  auth_out(200, [
    "ok" => true,
    "status" => [
      "user_id" => (int)$row["user_id"],
      "account_status" => $row["account_status"],
      "valid" => $row["valid"],
      "station_id" => $row["station_id"] ? (int)$row["station_id"] : null,
      "station_name" => $row["station_name"],
      "verification_status" => $row["verification_status"],
      "submitted_at" => $row["submitted_at"],
      "reviewed_at" => $row["reviewed_at"],
      "approved_at" => $row["approved_at"],
      "rejection_reason" => $row["rejection_reason"] ?: $row["user_rejected_reason"],
      "document_count" => (int)($row["document_count"] ?? 0)
    ]
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Server error. Please try again later."
  ]);
}