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

function clean($value): string {
  return trim((string)($value ?? ""));
}

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    out(405, ["ok" => false, "message" => "Method not allowed"]);
  }

  $data = json_input();

  $id = isset($data["id"]) && is_numeric($data["id"]) ? (int)$data["id"] : 0;
  $crimeName = clean($data["crime_name"] ?? "");
  $crimeCategory = strtoupper(clean($data["crime_category"] ?? "OTHER"));
  $focusCrimeCode = clean($data["focus_crime_code"] ?? "");
  $cirasOffenseCode = clean($data["ciras_offense_code"] ?? "");
  $severityWeight = $data["severity_weight"] ?? 2;

  $allowedCategories = ["INDEX", "NON_INDEX", "SPECIAL_LAW", "OTHER"];

  if ($crimeName === "") {
    out(422, ["ok" => false, "message" => "Crime name is required"]);
  }

  if (!in_array($crimeCategory, $allowedCategories, true)) {
    out(422, ["ok" => false, "message" => "Invalid crime category"]);
  }

  if (!is_numeric($severityWeight)) {
    out(422, ["ok" => false, "message" => "Severity weight must be numeric"]);
  }

  $severityWeight = round((float)$severityWeight, 2);

  if ($severityWeight < 1 || $severityWeight > 10) {
    out(422, ["ok" => false, "message" => "Severity weight must be between 1 and 10"]);
  }

  $focusCrimeCode = $focusCrimeCode !== "" ? $focusCrimeCode : null;
  $cirasOffenseCode = $cirasOffenseCode !== "" ? $cirasOffenseCode : null;

  if ($id > 0) {
    $checkStmt = $pdo->prepare("
      SELECT id
      FROM crime_types
      WHERE id = ?
        AND is_active = 1
      LIMIT 1
    ");
    $checkStmt->execute([$id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
      out(404, ["ok" => false, "message" => "Crime type not found"]);
    }

    $dupStmt = $pdo->prepare("
      SELECT id
      FROM crime_types
      WHERE LOWER(TRIM(crime_name)) = LOWER(TRIM(?))
        AND id <> ?
        AND is_active = 1
      LIMIT 1
    ");
    $dupStmt->execute([$crimeName, $id]);
    $duplicate = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($duplicate) {
      out(422, ["ok" => false, "message" => "Another active crime type already uses this name"]);
    }

    $stmt = $pdo->prepare("
      UPDATE crime_types
      SET
        crime_name = ?,
        crime_category = ?,
        focus_crime_code = ?,
        ciras_offense_code = ?,
        severity_weight = ?
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->execute([
      $crimeName,
      $crimeCategory,
      $focusCrimeCode,
      $cirasOffenseCode,
      $severityWeight,
      $id
    ]);

    out(200, [
      "ok" => true,
      "message" => "Crime type updated successfully",
      "id" => $id
    ]);
  }

  $dupStmt = $pdo->prepare("
    SELECT id
    FROM crime_types
    WHERE LOWER(TRIM(crime_name)) = LOWER(TRIM(?))
      AND is_active = 1
    LIMIT 1
  ");
  $dupStmt->execute([$crimeName]);
  $duplicate = $dupStmt->fetch(PDO::FETCH_ASSOC);

  if ($duplicate) {
    out(422, ["ok" => false, "message" => "Crime type already exists"]);
  }

  $stmt = $pdo->prepare("
    INSERT INTO crime_types
    (
      crime_category,
      focus_crime_code,
      crime_name,
      ciras_offense_code,
      severity_weight,
      is_active
    )
    VALUES (?, ?, ?, ?, ?, 1)
  ");
  $stmt->execute([
    $crimeCategory,
    $focusCrimeCode,
    $crimeName,
    $cirasOffenseCode,
    $severityWeight
  ]);

  out(200, [
    "ok" => true,
    "message" => "Crime type added successfully",
    "id" => (int)$pdo->lastInsertId()
  ]);

} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}