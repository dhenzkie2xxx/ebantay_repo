<?php

if (!function_exists("write_audit_log")) {

function write_audit_log(
  PDO $pdo,
  array $actor,
  string $actionType,
  string $entityType = null,
  $entityId = null,
  string $description = null,
  array $meta = []
) {

  try {

    $stmt = $pdo->prepare("
      INSERT INTO audit_logs (
        station_id,
        actor_user_id,
        actor_role,

        action_type,
        entity_type,
        entity_id,

        description,

        related_assignment_id,
        related_incident_id,
        related_panic_id,
        target_user_id,

        ip_address,
        user_agent

      ) VALUES (
        ?,?,?,?,?,?,
        ?,?,?,?,?,
        ?,?
      )
    ");

    $stmt->execute([
      isset($actor["station_id"])
        ? (int)$actor["station_id"]
        : null,

      (int)$actor["id"],
      (string)$actor["role"],

      $actionType,
      $entityType,
      $entityId,

      $description,

      $meta["assignment_id"] ?? null,
      $meta["incident_id"] ?? null,
      $meta["panic_id"] ?? null,
      $meta["target_user_id"] ?? null,

      $_SERVER["REMOTE_ADDR"] ?? null,
      $_SERVER["HTTP_USER_AGENT"] ?? null
    ]);

  } catch(Throwable $e){
      error_log("Audit log failed: ".$e->getMessage());
  }

}

}