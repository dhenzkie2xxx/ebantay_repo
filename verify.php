<?php
require_once __DIR__ . "/db.php";

function wantsJson() {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  return (stripos($accept, 'application/json') !== false) || (strtolower($xhr) === 'xmlhttprequest');
}

function jsonOut($code, $payload) {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($payload);
  exit;
}

function renderPage($title, $status, $message, $ctaText = null, $ctaHref = null) {
  // Simple brand colors (blue/red), responsive layout
  $bg = "#F4F7FF";
  $card = "#FFFFFF";
  $blue = "#1D4ED8";
  $red = "#DC2626";
  $text = "#0F172A";
  $muted = "#64748B";
  $border = "#E2E8F0";

  $isSuccess = ($status === "success");
  $accent = $isSuccess ? $blue : $red;
  $icon = $isSuccess ? "✓" : "!";
  $statusLabel = $isSuccess ? "Email Verified" : "Verification Failed";

  header('Content-Type: text/html; charset=UTF-8');
  echo "<!doctype html>
  <html lang='en'>
  <head>
    <meta charset='utf-8' />
    <meta name='viewport' content='width=device-width, initial-scale=1' />
    <title>{$title}</title>
    <style>
      body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:{$bg};color:{$text};}
      .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
      .card{width:100%;max-width:520px;background:{$card};border:1px solid {$border};border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);overflow:hidden;}
      .top{padding:18px 22px;background:linear-gradient(90deg, {$blue}, #0B2A6F);color:#fff;text-align: center;}
      .brand{font-weight:800;letter-spacing:.3px;font-size:18px;}
      .sub{opacity:.85;margin-top:4px;font-size:12px;}
      .content{padding:22px;}
      .badge{display:inline-flex;align-items:center;gap:10px;padding:10px 12px;border-radius:999px;background:rgba(29,78,216,.08);border:1px solid rgba(29,78,216,.22);margin-bottom:14px;}
      .dot{width:34px;height:34px;border-radius:999px;background:{$accent};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;}
      .badge span{font-weight:800;}
      h1{margin:0 0 8px 0;font-size:20px;}
      p{margin:0 0 12px 0;color:{$muted};line-height:1.45;}
      .cta{display:inline-block;margin-top:10px;background:{$accent};color:#fff;text-decoration:none;padding:12px 14px;border-radius:12px;font-weight:800;}
      .muted{margin-top:16px;font-size:12px;color:{$muted};}
      .footer{padding:14px 22px;border-top:1px solid {$border};font-size:12px;color:{$muted};display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;}
      .pill{padding:6px 10px;border-radius:999px;background:#F1F5F9;border:1px solid {$border};}
      .logo-wrap{text-align: center;margin-bottom: 10px;}.logo-wrap img{max-width: 220px;width: 70%;height: auto;}
    </style>
  </head>
  <body>
    <div class='wrap'>
      <div class='card'>
        <div class='top'>
          <div class='logo-wrap'>
            <img src='https://ebantay.top.gen.in/assets/logowhite.png' alt='eBantay Logo' />
          </div>
          <div class='sub'>Community Safety Reporting Application</div>
        </div>
        <div class='content'>
          <div class='badge'>
            <div class='dot'>{$icon}</div>
            <span>{$statusLabel}</span>
          </div>

          <h1>{$title}</h1>
          <p>{$message}</p>";

  if ($ctaText && $ctaHref) {
    echo "<a class='cta' href='{$ctaHref}'>{$ctaText}</a>";
  }

  echo " <div class='muted'>
            If you opened this from your phone, you may return to the app and sign in.
          </div>
        </div>
        <div class='footer'>
          <div class='pill'>© " . date("Y") . " eBantay</div>
          <div class='pill'>Powered by Resend</div>
        </div>
      </div>
    </div>
  </body>
  </html>";
  exit;
}

// ---- MAIN ----
$token = trim($_GET["token"] ?? "");

if ($token === "") {
  if (wantsJson()) jsonOut(400, ["ok"=>false, "message"=>"Missing token"]);
  renderPage("Invalid Link", "error", "The verification link is missing a token. Please request a new verification email.");
}

// Find user by token
$stmt = $pdo->prepare("SELECT id, email_verify_expires, is_email_verified FROM users WHERE email_verify_token = ? LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  if (wantsJson()) jsonOut(404, ["ok"=>false, "message"=>"Invalid or already used token"]);
  renderPage("Invalid or Used Link", "error", "This verification link is invalid or has already been used. Please request a new verification email.");
}

if ((int)$user["is_email_verified"] === 1) {
  if (wantsJson()) jsonOut(200, ["ok"=>true, "message"=>"Email already verified"]);
  renderPage("Already Verified", "success", "Your email address is already verified. You can now sign in to your eBantay account.");
}

// Check expiry
$expires = $user["email_verify_expires"] ? strtotime($user["email_verify_expires"]) : 0;
if ($expires > 0 && time() > $expires) {
  // Optional: clear expired token to prevent reuse
  $clear = $pdo->prepare("UPDATE users SET email_verify_token = NULL, email_verify_expires = NULL WHERE id = ?");
  $clear->execute([$user["id"]]);

  if (wantsJson()) jsonOut(410, ["ok"=>false, "message"=>"Token expired"]);
  renderPage("Link Expired", "error", "This verification link has expired. Please request a new verification email.");
}

// Mark verified
$upd = $pdo->prepare("UPDATE users SET is_email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL WHERE id = ?");
$upd->execute([$user["id"]]);

// Optional CTA: point to your app download or web login page
$ctaHref = "https://top.gen.in"; // change if you have a login page
$ctaText = "Go to eBantay";

if (wantsJson()) jsonOut(200, ["ok"=>true, "message"=>"Email verified"]);
renderPage("Verification Successful", "success", "Your email has been verified successfully! You can now return to the app and log in.", $ctaText, $ctaHref);
