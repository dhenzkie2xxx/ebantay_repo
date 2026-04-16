<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/auth_helpers.php";

$allowedOrigins = [
  "http://localhost:5173",
  "http://127.0.0.1:5173",
  "https://ebantay.top.gen.in",
];

$origin = $_SERVER["HTTP_ORIGIN"] ?? "";
if ($origin && in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit;
}

function out_json($code, $payload) {
  http_response_code($code);
  header("Content-Type: application/json; charset=UTF-8");
  echo json_encode($payload);
  exit;
}

function get_bearer_or_query_token(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

function normalize_scope_value($value): ?string {
  $value = trim((string)($value ?? ""));
  return $value === "" ? null : $value;
}

function can_admin_access_user(PDO $pdo, array $adminUser, int $targetUserId): bool {
  $role = strtolower((string)($adminUser["role"] ?? ""));
  if ($role === "super_admin") return true;
  if ($role !== "admin") return false;

  $stationStmt = $pdo->prepare("
    SELECT
      ps.id,
      ps.city_municipality,
      ps.province
    FROM users u
    INNER JOIN police_stations ps ON ps.id = u.station_id
    WHERE u.id = ?
    LIMIT 1
  ");
  $stationStmt->execute([(int)$adminUser["id"]]);
  $station = $stationStmt->fetch(PDO::FETCH_ASSOC);

  if (!$station) return false;

  $scopeProvince = normalize_scope_value($station["province"] ?? null);
  $scopeCity = normalize_scope_value($station["city_municipality"] ?? null);

  if (!$scopeProvince || !$scopeCity) return false;

  $userStmt = $pdo->prepare("
    SELECT
      u.id,
      up.province,
      up.city_municipality
    FROM users u
    LEFT JOIN user_profiles up ON up.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'citizen'
    LIMIT 1
  ");
  $userStmt->execute([$targetUserId]);
  $target = $userStmt->fetch(PDO::FETCH_ASSOC);

  if (!$target) return false;

  $userProvince = normalize_scope_value($target["province"] ?? null);
  $userCity = normalize_scope_value($target["city_municipality"] ?? null);

  if (!$userProvince || !$userCity) return false;

  return strcasecmp($scopeProvince, $userProvince) === 0
    && strcasecmp($scopeCity, $userCity) === 0;
}

try {
  $token = get_bearer_or_query_token();
  if ($token === "") {
    out_json(401, ["ok" => false, "message" => "Missing token"]);
  }

  $authUser = auth_get_user_by_token($pdo, $token);
  if (!$authUser) {
    out_json(401, ["ok" => false, "message" => "Unauthorized"]);
  }

  if (auth_check_token_expired($authUser)) {
    out_json(401, ["ok" => false, "message" => "Token expired"]);
  }

  $documentId = (int)($_GET["id"] ?? 0);
  if ($documentId <= 0) {
    out_json(400, ["ok" => false, "message" => "Missing or invalid document id"]);
  }

  $mode = strtolower(trim((string)($_GET["mode"] ?? "preview")));
  if (!in_array($mode, ["preview", "download"], true)) {
    $mode = "preview";
  }

  $stmt = $pdo->prepare("
    SELECT
      s.id,
      s.user_id,
      s.requirement_id,
      s.file_name,
      s.mime_type,
      s.file_size,
      s.document_blob,
      s.status,
      s.remarks,
      s.uploaded_at,
      r.requirement_code,
      r.requirement_name
    FROM user_requirement_submissions s
    INNER JOIN user_verification_requirements r
      ON r.id = s.requirement_id
    WHERE s.id = ?
    LIMIT 1
  ");
  $stmt->execute([$documentId]);
  $doc = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$doc) {
    out_json(404, ["ok" => false, "message" => "Document not found"]);
  }

  $viewerRole = strtolower((string)($authUser["role"] ?? ""));
  $viewerId = (int)($authUser["id"] ?? 0);
  $ownerUserId = (int)$doc["user_id"];

  $allowed = false;

  if ($viewerRole === "citizen" && $viewerId === $ownerUserId) {
    $allowed = true;
  }

  if (!$allowed && in_array($viewerRole, ["admin", "super_admin"], true)) {
    $allowed = can_admin_access_user($pdo, $authUser, $ownerUserId);
  }

  if (!$allowed) {
    out_json(403, ["ok" => false, "message" => "Access denied"]);
  }

  $mimeType = trim((string)($doc["mime_type"] ?? ""));
  if ($mimeType === "") {
    $mimeType = "application/octet-stream";
  }

  $fileName = trim((string)($doc["file_name"] ?? ""));
  if ($fileName === "") {
    $ext = "bin";
    if ($mimeType === "image/jpeg") $ext = "jpg";
    elseif ($mimeType === "image/png") $ext = "png";
    elseif ($mimeType === "application/pdf") $ext = "pdf";

    $fileName = "user_document_" . $documentId . "." . $ext;
  }

  $blob = $doc["document_blob"];
  if ($blob === null) {
    out_json(404, ["ok" => false, "message" => "Document file is empty"]);
  }

  if ($mode === "download") {
    header("Content-Type: " . $mimeType);
    header("Content-Length: " . strlen($blob));
    header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
    header("Cache-Control: private, max-age=0, must-revalidate");
    header("Pragma: public");
    echo $blob;
    exit;
  }

  header("Content-Type: " . $mimeType);
  header("Content-Length: " . strlen($blob));
  header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');
  header("Cache-Control: private, max-age=0, must-revalidate");
  header("Pragma: public");
  echo $blob;
  exit;

} catch (Throwable $e) {
  out_json(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}