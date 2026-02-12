<?php

function sendVerificationEmail($toEmail, $toName, $verifyLink) {
    $apiKey = getenv('RESEND_API_KEY');
    $from   = getenv('MAIL_FROM');

    if (!$apiKey || !$from) {
        error_log("Email config missing.");
        return false;
    }

    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

    $payload = json_encode([
        "from" => $from,
        "to" => [$toEmail],
        "subject" => "Verify your eBantay account",
        "html" => "
            <div style='font-family:Arial,sans-serif'>
                <h2 style='color:#1D4ED8;'>eBantay Email Verification</h2>
                <p>Hello <strong>{$safeName}</strong>,</p>
                <p>Please verify your email by clicking the button below:</p>
                <p>
                    <a href='{$verifyLink}' 
                       style='background:#1D4ED8;
                              color:white;
                              padding:10px 18px;
                              text-decoration:none;
                              border-radius:5px;'>
                        Verify Email
                    </a>
                </p>
                <p>If you did not create this account, please ignore this email.</p>
                <hr>
                <small>© " . date('Y') . " eBantay Philippines</small>
            </div>
        "
    ]);

    $ch = curl_init("https://api.resend.com/emails");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("Resend error ({$code}): {$response}");
        return false;
    }

    return true;
}

?>