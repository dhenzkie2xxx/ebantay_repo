<?php

if (!function_exists("audit_json_or_null")) {
  function audit_json_or_null($value): ?string {
    if ($value === null) return null;

    if (is_string($value)) {
      $trimmed = trim($value);
      if ($trimmed === "") return null;
      return $trimmed;
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
}

if (!function_exists("write_audit_log")) {

function write_audit_log(
  PDO $pdo,
  array $actor,
  string $actionType,
  ?string $entityType = null,
  $entityId = null,
  ?string $description = null,
  array $meta = []
): void {

  try {
    $oldValues = $meta["old_values"] ?? null;
    $newValues = $meta["new_values"] ?? null;

    $stmt = $pdo->prepare("
      INSERT INTO audit_logs (
        station_id,
        actor_user_id,
        actor_role,

        module,
        action_type,
        entity_type,
        entity_id,

        description,
        old_values,
        new_values,

        related_assignment_id,
        related_incident_id,
        related_panic_id,
        target_user_id,

        ip_address,
        user_agent
      ) VALUES (
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?
      )
    ");

    $stmt->execute([
      isset($actor["station_id"]) ? (int)$actor["station_id"] : null,
      (int)$actor["id"],
      (string)$actor["role"],

      $meta["module"] ?? null,
      $actionType,
      $entityType,
      $entityId,

      $description,
      audit_json_or_null($oldValues),
      audit_json_or_null($newValues),

      $meta["assignment_id"] ?? null,
      $meta["incident_id"] ?? null,
      $meta["panic_id"] ?? null,
      $meta["target_user_id"] ?? null,

      $_SERVER["REMOTE_ADDR"] ?? null,
      $_SERVER["HTTP_USER_AGENT"] ?? null
    ]);

  } catch (Throwable $e) {
    error_log("Audit log failed: " . $e->getMessage());
  }
}

}