<?php
require_once __DIR__ . "/db.php";

function queue_user_alert(PDO $pdo, int $userId, string $type, string $title, string $message, string $severity = "MEDIUM"): void {
  $severity = strtoupper(trim($severity));
  if (!in_array($severity, ["LOW", "MEDIUM", "HIGH"], true)) {
    $severity = "MEDIUM";
  }

  $stmt = $pdo->prepare("
    INSERT INTO notification_alerts
    (
      user_id,
      type,
      title,
      message,
      severity,
      is_read,
      created_at
    )
    VALUES (?, ?, ?, ?, ?, 0, NOW())
  ");
  $stmt->execute([$userId, $type, $title, $message, $severity]);
}

function get_false_thresholds(): array {
  return [
    "false_report" => 3,
    "false_alarm" => 3,
  ];
}

function insert_user_flag_audit(
  PDO $pdo,
  int $userId,
  string $sourceType,
  ?int $sourceId,
  string $actionType,
  int $oldFalseReportCount,
  int $newFalseReportCount,
  int $oldFalseAlarmCount,
  int $newFalseAlarmCount,
  string $oldFlagStatus,
  string $newFlagStatus,
  ?string $remarks,
  ?int $actedBy
): void {
  $stmt = $pdo->prepare("
    INSERT INTO user_account_flags
    (
      user_id,
      source_type,
      source_id,
      action_type,
      old_false_report_count,
      new_false_report_count,
      old_false_alarm_count,
      new_false_alarm_count,
      old_flag_status,
      new_flag_status,
      remarks,
      acted_by,
      created_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  $stmt->execute([
    $userId,
    $sourceType,
    $sourceId,
    $actionType,
    $oldFalseReportCount,
    $newFalseReportCount,
    $oldFalseAlarmCount,
    $newFalseAlarmCount,
    $oldFlagStatus,
    $newFlagStatus,
    $remarks,
    $actedBy,
  ]);
}

function flag_user_after_false_report(
  PDO $pdo,
  int $userId,
  int $incidentId,
  ?int $actedBy = null
): array {
  $thresholds = get_false_thresholds();

  $stmt = $pdo->prepare("
    SELECT
      false_report_count,
      false_alarm_count,
      account_flag_status
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    throw new RuntimeException("User not found while flagging false report");
  }

  $oldFR = (int)$user["false_report_count"];
  $oldFA = (int)$user["false_alarm_count"];
  $oldStatus = (string)($user["account_flag_status"] ?? "none");

  $newFR = $oldFR + 1;
  $newStatus = $oldStatus;
  $flaggedNow = false;

  if ($oldStatus === "none" && $newFR >= $thresholds["false_report"]) {
    $newStatus = "flagged";
    $flaggedNow = true;
  }

  $update = $pdo->prepare("
    UPDATE users
    SET
      false_report_count = ?,
      account_flag_status = ?,
      flagged_at = CASE
        WHEN ? = 'flagged' AND account_flag_status = 'none' THEN NOW()
        ELSE flagged_at
      END,
      flagged_reason = CASE
        WHEN ? = 'flagged' AND account_flag_status = 'none'
          THEN 'Reached false report threshold'
        ELSE flagged_reason
      END,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $update->execute([
    $newFR,
    $newStatus,
    $newStatus,
    $newStatus,
    $userId,
  ]);

  insert_user_flag_audit(
    $pdo,
    $userId,
    'incident_report',
    $incidentId,
    'false_report_increment',
    $oldFR,
    $newFR,
    $oldFA,
    $oldFA,
    $oldStatus,
    $newStatus,
    'Incident marked as FALSE_REPORT',
    $actedBy
  );

  if ($flaggedNow) {
    insert_user_flag_audit(
      $pdo,
      $userId,
      'incident_report',
      $incidentId,
      'flagged',
      $oldFR,
      $newFR,
      $oldFA,
      $oldFA,
      $oldStatus,
      $newStatus,
      'User automatically flagged after repeated false reports',
      $actedBy
    );

    queue_user_alert(
      $pdo,
      $userId,
      'ACCOUNT_STATUS',
      'Account Flagged',
      'Your account has been flagged due to repeated false reports. The station admin may review and suspend your access.',
      'HIGH'
    );
  }

  return [
    "user_id" => $userId,
    "false_report_count" => $newFR,
    "false_alarm_count" => $oldFA,
    "account_flag_status" => $newStatus,
    "flagged_now" => $flaggedNow,
  ];
}

function flag_user_after_false_alarm(
  PDO $pdo,
  int $userId,
  int $panicId,
  ?int $actedBy = null
): array {
  $thresholds = get_false_thresholds();

  $stmt = $pdo->prepare("
    SELECT
      false_report_count,
      false_alarm_count,
      account_flag_status
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$userId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    throw new RuntimeException("User not found while flagging false alarm");
  }

  $oldFR = (int)$user["false_report_count"];
  $oldFA = (int)$user["false_alarm_count"];
  $oldStatus = (string)($user["account_flag_status"] ?? "none");

  $newFA = $oldFA + 1;
  $newStatus = $oldStatus;
  $flaggedNow = false;

  if ($oldStatus === "none" && $newFA >= $thresholds["false_alarm"]) {
    $newStatus = "flagged";
    $flaggedNow = true;
  }

  $update = $pdo->prepare("
    UPDATE users
    SET
      false_alarm_count = ?,
      account_flag_status = ?,
      flagged_at = CASE
        WHEN ? = 'flagged' AND account_flag_status = 'none' THEN NOW()
        ELSE flagged_at
      END,
      flagged_reason = CASE
        WHEN ? = 'flagged' AND account_flag_status = 'none'
          THEN 'Reached false alarm threshold'
        ELSE flagged_reason
      END,
      updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $update->execute([
    $newFA,
    $newStatus,
    $newStatus,
    $newStatus,
    $userId,
  ]);

  insert_user_flag_audit(
    $pdo,
    $userId,
    'panic_request',
    $panicId,
    'false_alarm_increment',
    $oldFR,
    $oldFR,
    $oldFA,
    $newFA,
    $oldStatus,
    $newStatus,
    'Panic request marked as FALSE_ALARM',
    $actedBy
  );

  if ($flaggedNow) {
    insert_user_flag_audit(
      $pdo,
      $userId,
      'panic_request',
      $panicId,
      'flagged',
      $oldFR,
      $oldFR,
      $oldFA,
      $newFA,
      $oldStatus,
      $newStatus,
      'User automatically flagged after repeated false alarms',
      $actedBy
    );

    queue_user_alert(
      $pdo,
      $userId,
      'ACCOUNT_STATUS',
      'Account Flagged',
      'Your account has been flagged due to repeated false panic requests. The station admin may review and suspend your access.',
      'HIGH'
    );
  }

  return [
    "user_id" => $userId,
    "false_report_count" => $oldFR,
    "false_alarm_count" => $newFA,
    "account_flag_status" => $newStatus,
    "flagged_now" => $flaggedNow,
  ];
}