<?php

if (!function_exists("audit_json")) {
  function audit_json($value): ?string {
    if ($value === null) return null;

    $json = json_encode(
      $value,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    return $json === false ? null : $json;
  }
}

if (!function_exists("audit_client_ip")) {
  function audit_client_ip(): ?string {
    foreach ([
      "HTTP_CF_CONNECTING_IP",
      "HTTP_X_FORWARDED_FOR",
      "HTTP_X_REAL_IP",
      "REMOTE_ADDR"
    ] as $key) {
      if (!empty($_SERVER[$key])) {
        $ip = trim(explode(",", $_SERVER[$key])[0]);
        return substr($ip, 0, 80);
      }
    }
    return null;
  }
}

if (!function_exists("audit_module_from_entity")) {
  function audit_module_from_entity(?string $entityType): ?string {
    $e = strtolower((string)$entityType);

    if (str_contains($e, "incident")) return "incident_reports";
    if (str_contains($e, "panic")) return "panic_requests";
    if (str_contains($e, "blotter")) return "blotter";
    if (str_contains($e, "assignment") || str_contains($e, "dispatch")) return "dispatch_queue";
    if ($e === "user") return "users";
    if (str_contains($e, "announcement")) return "announcements";

    return $entityType ?: null;
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
      $module = $meta["module"] ?? audit_module_from_entity($entityType);

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
          ?,?,?,?,?,?,
          ?,?,?,?,?,?,
          ?,?,?,?
        )
      ");

      $stmt->execute([
        isset($actor["station_id"]) ? (int)$actor["station_id"] : null,
        (int)$actor["id"],
        (string)$actor["role"],
        $module,

        $actionType,
        $entityType,
        $entityId !== null ? (int)$entityId : null,
        $description,

        audit_json($meta["old_values"] ?? null),
        audit_json($meta["new_values"] ?? null),

        $meta["assignment_id"] ?? null,
        $meta["incident_id"] ?? null,
        $meta["panic_id"] ?? null,
        $meta["target_user_id"] ?? null,

        audit_client_ip(),
        $_SERVER["HTTP_USER_AGENT"] ?? null
      ]);
    } catch (Throwable $e) {
      error_log("Audit log failed: " . $e->getMessage());
    }
  }
}