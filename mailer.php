<?php

function sendVerificationEmail($toEmail, $toName, $verifyLink) {
  $apiKey = getenv('RESEND_API_KEY');
  $from   = getenv('MAIL_FROM') ?: 'onboarding@resend.dev';

  if (!$apiKey) {
    error_log("RESEND_API_KEY not set");
    return false;
  }

  $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

  $subject = "Verify your eBantay account";
  $html = "
    <h2>Email Verification</h2>
    <p>Hello Kabayan! <b>{$safeName}</b>,</p>
    <p>Please verify your email by clicking below:</p>
    <p><a href='{$verifyLink}' style='color:#1D4ED8;font-weight:bold;'>Verify Email</a></p>
    <p>Please take note that this is only the first step for verification.</p>
    <p>You still need to complete setting up your account once you log in to fully access the features of eBantay.</p>
    <p>Thank you.</p>
  ";

  $payload = json_encode([
    "from" => $from,
    "to" => [$toEmail],
    "subject" => $subject,
    "html" => $html
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

  $resp = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($errno) {
    error_log("Resend cURL error: {$err}");
    return false;
  }

  if ($code < 200 || $code >= 300) {
    error_log("Resend failed ({$code}): {$resp}");
    return false;
  }

  return true;
}
