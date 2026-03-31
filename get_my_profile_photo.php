<?php
require_once __DIR__ . "/auth_helpers.php";

function profile_token_from_request(): string {
  $token = bearer_token();
  if ($token !== "") return $token;

  $queryToken = trim((string)($_GET["token"] ?? ""));
  if ($queryToken !== "") return $queryToken;

  return "";
}

function avatar_initials(?string $firstname, ?string $lastname, ?string $username = null): string {
  $first = trim((string)$firstname);
  $last = trim((string)$lastname);
  $user = trim((string)$username);

  $initials = "";

  if ($first !== "") {
    $initials .= mb_strtoupper(mb_substr($first, 0, 1));
  }

  if ($last !== "") {
    $initials .= mb_strtoupper(mb_substr($last, 0, 1));
  }

  if ($initials === "" && $user !== "") {
    $parts = preg_split('/[\s._-]+/u', $user);
    foreach ($parts as $part) {
      $part = trim((string)$part);
      if ($part !== "") {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($initials) >= 2) break;
      }
    }

    if ($initials === "") {
      $initials = mb_strtoupper(mb_substr($user, 0, 1));
    }
  }

  if ($initials === "") {
    $initials = "U";
  }

  return mb_substr($initials, 0, 2);
}

function avatar_colors_from_seed(string $seed): array {
  $palettes = [
    ["#1D4ED8", "#2563EB"],
    ["#7C3AED", "#9333EA"],
    ["#0F766E", "#14B8A6"],
    ["#BE123C", "#E11D48"],
    ["#C2410C", "#F97316"],
    ["#0F172A", "#334155"],
    ["#166534", "#22C55E"],
    ["#4338CA", "#6366F1"]
  ];

  $hash = crc32($seed);
  $index = abs((int)$hash) % count($palettes);
  return $palettes[$index];
}

function output_default_avatar(?string $firstname = null, ?string $lastname = null, ?string $username = null): void {
  $initials = avatar_initials($firstname, $lastname, $username);
  [$c1, $c2] = avatar_colors_from_seed($initials . "|" . (string)$firstname . "|" . (string)$lastname . "|" . (string)$username);

  $safeInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");

  $svg = <<<SVG
<svg width="128" height="128" viewBox="0 0 128 128" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Default avatar">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="$c1"/>
      <stop offset="100%" stop-color="$c2"/>
    </linearGradient>
  </defs>
  <rect width="128" height="128" rx="64" fill="url(#g)"/>
  <circle cx="64" cy="64" r="58" fill="rgba(255,255,255,0.08)"/>
  <text
    x="64"
    y="64"
    text-anchor="middle"
    dominant-baseline="central"
    font-family="Arial, Helvetica, sans-serif"
    font-size="42"
    font-weight="700"
    fill="white"
    letter-spacing="1"
  >$safeInitials</text>
</svg>
SVG;

  http_response_code(200);
  header("Content-Type: image/svg+xml; charset=UTF-8");
  header("Cache-Control: public, max-age=300");
  header('Content-Disposition: inline; filename="default-avatar.svg"');
  echo $svg;
  exit;
}

$token = profile_token_from_request();
if ($token === "") {
  output_default_avatar();
}

$user = auth_get_user_by_token($pdo, $token);
if (!$user) {
  output_default_avatar();
}

if (auth_check_token_expired($user)) {
  output_default_avatar(
    $user["firstname"] ?? null,
    $user["lastname"] ?? null,
    $user["username"] ?? null
  );
}

try {
  $stmt = $pdo->prepare("
    SELECT firstname, lastname, username, profile_photo, profile_photo_mime, profile_photo_name
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([(int)$user["id"]]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row["profile_photo"])) {
    output_default_avatar(
      $row["firstname"] ?? ($user["firstname"] ?? null),
      $row["lastname"] ?? ($user["lastname"] ?? null),
      $row["username"] ?? ($user["username"] ?? null)
    );
  }

  $mime = trim((string)($row["profile_photo_mime"] ?? ""));
  if ($mime === "") {
    $mime = "application/octet-stream";
  }

  $fileName = trim((string)($row["profile_photo_name"] ?? "profile"));
  $fileName = str_replace('"', "", $fileName);

  http_response_code(200);
  header("Content-Type: " . $mime);
  header("Content-Length: " . strlen($row["profile_photo"]));
  header("Cache-Control: private, max-age=300");
  header('Content-Disposition: inline; filename="' . $fileName . '"');
  echo $row["profile_photo"];
  exit;

} catch (Throwable $e) {
  output_default_avatar(
    $user["firstname"] ?? null,
    $user["lastname"] ?? null,
    $user["username"] ?? null
  );
}