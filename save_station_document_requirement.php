<?php
require_once __DIR__ . "/require_super_admin.php";
require_once __DIR__ . "/station_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  auth_out(405, [
    "ok" => false,
    "message" => "Method not allowed"
  ]);
}

$data = station_json_input();

$id = (int)($data["id"] ?? 0);
$name = station_clean($data["requirement_name"] ?? "");
$isRequired = isset($data["is_required"]) ? (int)$data["is_required"] : 1;
$active = isset($data["active"]) ? (int)$data["active"] : 1;

if ($name === "") {
  auth_out(400, [
    "ok" => false,
    "message" => "Requirement name is required."
  ]);
}

if ($isRequired !== 0 && $isRequired !== 1) {
  $isRequired = 1;
}

if ($active !== 0 && $active !== 1) {
  $active = 1;
}

function station_requirement_code_from_name(string $name): string {
  $code = strtolower(trim($name));
  $code = preg_replace('/[^a-z0-9]+/', '_', $code);
  $code = trim($code, '_');

  if ($code === "") {
    $code = "custom_requirement";
  }

  return substr($code, 0, 100);
}

try {
  if ($id > 0) {
    $stmt = $pdo->prepare("
      SELECT id, is_system
      FROM station_document_requirements
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
      auth_out(404, [
        "ok" => false,
        "message" => "Requirement not found."
      ]);
    }

    if ((int)$existing["is_system"] === 1) {
      $upd = $pdo->prepare("
        UPDATE station_document_requirements
        SET
          is_required = 1,
          active = 1
        WHERE id = ?
      ");
      $upd->execute([$id]);

      auth_out(200, [
        "ok" => true,
        "message" => "Default requirement is kept active and required."
      ]);
    }

    $upd = $pdo->prepare("
      UPDATE station_document_requirements
      SET
        requirement_name = ?,
        is_required = ?,
        active = ?
      WHERE id = ?
    ");
    $upd->execute([
      $name,
      $isRequired,
      $active,
      $id
    ]);

    auth_out(200, [
      "ok" => true,
      "message" => "Requirement updated successfully."
    ]);
  }

  $baseCode = station_requirement_code_from_name($name);
  $code = $baseCode;
  $counter = 2;

  while (true) {
    $check = $pdo->prepare("
      SELECT id
      FROM station_document_requirements
      WHERE requirement_code = ?
      LIMIT 1
    ");
    $check->execute([$code]);

    if (!$check->fetch(PDO::FETCH_ASSOC)) {
      break;
    }

    $suffix = "_" . $counter;
    $code = substr($baseCode, 0, 100 - strlen($suffix)) . $suffix;
    $counter++;
  }

  $ins = $pdo->prepare("
    INSERT INTO station_document_requirements (
      requirement_code,
      requirement_name,
      is_required,
      is_system,
      active,
      created_by
    ) VALUES (?, ?, ?, 0, ?, ?)
  ");
  $ins->execute([
    $code,
    $name,
    $isRequired,
    $active,
    $AUTH_USER["id"]
  ]);

  auth_out(200, [
    "ok" => true,
    "message" => "Requirement added successfully."
  ]);
} catch (Throwable $e) {
  auth_out(500, [
    "ok" => false,
    "message" => "Failed to save station document requirement."
  ]);
}