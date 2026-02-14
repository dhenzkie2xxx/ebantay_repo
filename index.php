<?php
// Change these to match your app + file paths
$APP_NAME = "eBantay";
$TAGLINE  = "Community Safety Reporting Application";
$APK_URL  = "web/downloads/ebantay-latest.apk";    // relative to domain
$LOGO_URL = "/assets/logowhite.png";           // wide logo (white)
$DEEPLINK = "ebantay://login";                 // deep link
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?php echo htmlspecialchars($APP_NAME); ?> — Download</title>
  <meta name="description" content="Download the eBantay mobile app APK and start reporting safely in real time." />

  <!-- Optional: Open Graph preview when sharing -->
  <meta property="og:title" content="<?php echo htmlspecialchars($APP_NAME); ?>" />
  <meta property="og:description" content="<?php echo htmlspecialchars($TAGLINE); ?>" />
  <meta property="og:type" content="website" />

  <style>
    :root{
      --bg:#F4F7FF;
      --card:#FFFFFF;
      --text:#0F172A;
      --muted:#64748B;
      --border:#E2E8F0;
      --blue:#1D4ED8;
      --blueDark:#0B2A6F;
      --red:#DC2626;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text);}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;}
    .card{width:100%;max-width:920px;background:var(--card);border:1px solid var(--border);border-radius:22px;box-shadow:0 12px 30px rgba(0,0,0,.08);overflow:hidden;}
    .top{
      padding:26px 22px 18px 22px;
      background:linear-gradient(90deg,var(--blue),var(--blueDark));
      color:#fff;
      position:relative;
    }
    .top .logo{display:flex;justify-content:center;}
    .top img{width:min(360px,75%);height:auto;object-fit:contain;}
    .top .tagline{text-align:center;margin-top:8px;opacity:.9;font-size:13px}
    .bar{height:6px;background:linear-gradient(90deg,var(--blue),var(--red));}

    .content{padding:22px;display:grid;grid-template-columns:1.1fr .9fr;gap:18px;}
    @media (max-width: 860px){ .content{grid-template-columns:1fr;} }

    .panel{
      border:1px solid var(--border);
      border-radius:18px;
      padding:18px;
      background:#fff;
    }

    h1{margin:0 0 8px 0;font-size:22px}
    p{margin:0 0 12px 0;color:var(--muted);line-height:1.55}

    .btnRow{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px}
    a.btn, button.btn{
      appearance:none;border:0;cursor:pointer;
      padding:14px 16px;border-radius:14px;
      font-weight:900;font-size:14px;text-decoration:none;
      display:inline-flex;align-items:center;justify-content:center;gap:10px;
      transition:transform .12s ease, opacity .12s ease;
    }
    a.btn:hover, button.btn:hover{transform:scale(1.01)}
    .primary{background:linear-gradient(90deg,var(--blue),var(--red));color:#fff;}
    .secondary{background:#EFF6FF;border:1px solid #DBEAFE;color:var(--blueDark);}
    .ghost{background:#F8FAFC;border:1px solid var(--border);color:var(--text);}

    .badge{
      display:inline-flex;align-items:center;gap:10px;
      padding:10px 12px;border-radius:999px;
      background:rgba(29,78,216,.08);
      border:1px solid rgba(29,78,216,.18);
      margin-bottom:10px;
      font-weight:800;
    }
    .dot{width:10px;height:10px;border-radius:999px;background:var(--blue);}
    .small{font-size:12px;color:var(--muted)}
    .steps{margin:10px 0 0 0;padding-left:18px;color:var(--muted);line-height:1.55}
    .warn{
      margin-top:12px;
      padding:12px 12px;
      border-radius:14px;
      border:1px solid rgba(220,38,38,.25);
      background:rgba(220,38,38,.06);
      color:#7F1D1D;
      font-size:12px;
      display:none;
    }
    .hint{
      margin-top:12px;
      padding:12px 12px;
      border-radius:14px;
      border:1px solid rgba(29,78,216,.18);
      background:rgba(29,78,216,.06);
      color:#0B2A6F;
      font-size:12px;
      display:none;
    }
    .qr{
      display:flex;align-items:center;justify-content:center;
      height:180px;border-radius:16px;border:1px dashed var(--border);
      background:#F8FAFC;color:var(--muted);text-align:center;padding:12px
    }
    footer{padding:16px 22px;border-top:1px solid var(--border);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:12px}
    .pill{padding:6px 10px;border-radius:999px;background:#F1F5F9;border:1px solid var(--border);}
    code{background:#F1F5F9;border:1px solid var(--border);padding:2px 6px;border-radius:8px}
  </style>
</head>

<body>
  <div class="wrap">
    <div class="card">
      <div class="top">
        <div class="logo">
          <img src="<?php echo htmlspecialchars($LOGO_URL); ?>" alt="eBantay Logo" />
        </div>
        <div class="tagline"><?php echo htmlspecialchars($TAGLINE); ?></div>
      </div>
      <div class="bar"></div>

      <div class="content">
        <!-- Left: Main CTA -->
        <div class="panel">
          <div class="badge"><span class="dot"></span><span id="statusTitle">Open the app or download the APK</span></div>

          <h1>Get the <?php echo htmlspecialchars($APP_NAME); ?> mobile app</h1>
          <p id="statusText">
            Tap <b>Open App</b> if you already installed eBantay. If nothing happens, download the APK and install it.
          </p>

          <div class="btnRow">
            <button class="btn secondary" id="openAppBtn">↗ Open App</button>
            <a class="btn primary" id="downloadBtn" href="<?php echo htmlspecialchars($APK_URL); ?>" download>⬇ Download APK</a>
            <button class="btn ghost" id="howBtn">ℹ How to install</button>
          </div>

          <div class="hint" id="hintBox">
            <b>Tip:</b> If the app didn’t open, it may not be installed yet. Download the APK and install it.
          </div>

          <div class="warn" id="warnBox">
            <b>Android security note:</b> If you see “Blocked” or “Install unknown apps”, allow installation from your browser
            (Chrome) in Settings, then try again.
          </div>

          <ol class="steps" id="steps" style="display:none;">
            <li>Download the APK.</li>
            <li>Open the downloaded file to install.</li>
            <li>If prompted, enable <b>Install unknown apps</b> for your browser.</li>
            <li>After installing, return here and tap <b>Open App</b>.</li>
          </ol>

          <p class="small" style="margin-top:14px;">
            Deep link: <code><?php echo htmlspecialchars($DEEPLINK); ?></code>
          </p>
        </div>

        <!-- Right: Device-aware panel -->
        <div class="panel">
          <div class="badge"><span class="dot"></span><span>Device & Download</span></div>
          <p class="small" id="deviceInfo"></p>

          <div class="qr" id="qrBox">
            On desktop? Open this page on your Android phone to download the APK.
          </div>

          <p class="small" style="margin-top:12px;">
            If you plan to distribute to many users, consider publishing to Google Play later for easier installs.
          </p>
        </div>
      </div>

      <footer>
        <div class="pill">© <?php echo date("Y"); ?> eBantay</div>
        <div class="pill">Download served from top.gen.in</div>
      </footer>
    </div>
  </div>

  <script>
    const deepLink = <?php echo json_encode($DEEPLINK); ?>;
    const downloadUrl = <?php echo json_encode($APK_URL); ?>;

    const openBtn = document.getElementById("openAppBtn");
    const howBtn = document.getElementById("howBtn");
    const steps = document.getElementById("steps");
    const warnBox = document.getElementById("warnBox");
    const hintBox = document.getElementById("hintBox");
    const deviceInfo = document.getElementById("deviceInfo");
    const qrBox = document.getElementById("qrBox");
    const statusTitle = document.getElementById("statusTitle");
    const statusText = document.getElementById("statusText");

    const ua = navigator.userAgent || "";
    const isAndroid = /Android/i.test(ua);
    const isMobile = /Mobi|Android/i.test(ua);

    // Show device info
    deviceInfo.textContent = isAndroid
      ? "Detected: Android device. You can install the APK directly."
      : isMobile
        ? "Detected: Mobile device (non-Android). APK installs are Android-only."
        : "Detected: Desktop/Laptop. Use your Android phone to install the APK.";

    if (isAndroid) {
      qrBox.textContent = "Android detected ✅ Use the buttons on the left to open or download.";
    } else {
      // On desktop/non-android, keep the guidance message
      warnBox.style.display = "none";
    }

    function openApp() {
      // Attempt to open app
      window.location.href = deepLink;

      // If app not installed, after a short delay show hints
      setTimeout(() => {
        hintBox.style.display = "block";
        if (isAndroid) warnBox.style.display = "block";
      }, 1200);
    }

    openBtn.addEventListener("click", openApp);

    howBtn.addEventListener("click", () => {
      steps.style.display = steps.style.display === "none" ? "block" : "none";
    });

    // Auto-attempt open on Android (nice UX)
    // Only do this once per session to avoid annoyance
    if (isAndroid && !sessionStorage.getItem("ebantay_autotried")) {
      sessionStorage.setItem("ebantay_autotried", "1");
      statusTitle.textContent = "Trying to open eBantay…";
      statusText.textContent = "If the app is installed, it should open. If not, you can download the APK below.";
      setTimeout(openApp, 500);
    }
  </script>
</body>
</html>
