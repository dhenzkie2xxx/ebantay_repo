<?php
$token = trim((string)($_GET["token"] ?? ""));

if ($token === "") {
  echo "Missing reset token.";
  exit;
}

$safeToken = htmlspecialchars($token, ENT_QUOTES, "UTF-8");
$appLink = "ebantay://reset-password?token=" . urlencode($token);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Open eBantay</title>
</head>
<body style="font-family: Arial, sans-serif; background:#F4F7FF; padding:24px;">
  <div style="max-width:480px;margin:40px auto;background:white;padding:24px;border-radius:16px;text-align:center;">
    <h2 style="color:#1D4ED8;">Reset your eBantay password</h2>
    <p>Tap the button below to open the eBantay app and reset your password.</p>

    <a href="<?php echo $appLink; ?>"
       style="display:inline-block;background:#1D4ED8;color:white;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold;">
      Open eBantay App
    </a>

    <p style="font-size:12px;color:#64748B;margin-top:18px;">
      If the button does not work, make sure the eBantay app is installed.
    </p>
  </div>

  <script>
    setTimeout(function () {
      window.location.href = "<?php echo $appLink; ?>";
    }, 700);
  </script>
</body>
</html>