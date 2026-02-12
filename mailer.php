<?php

function sendVerificationEmail($toEmail, $toName, $verifyLink) {
  $apiKey = getenv('RESEND_API_KEY');

  // Use Resend test sender by default (works without domain verification)
  $from = getenv('MAIL_FROM') ?: 'onboarding@resend.dev';

  if (!$apiKey) {
    error_log("RESEND_API_KEY not set");
    return false;
  }

  $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');

  $payload = json_encode([
    "from" => $from,
    "to" => [$toEmail],
    "subject" => "Verify your eBantay account",
    "html" => "
      <h2>Email Verification</h2>
      <p>Hello Kabayan! <b>{$safeName}</b>,</p>
      <p>Please verify your email by clicking below:</p>
      <p><a href='{$verifyLink}'>Verify Email</a></p>
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

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    error_log("Resend cURL error: {$err}");
    return false;
  }

  if ($code < 200 || $code >= 300) {
    error_log("Resend failed ({$code}): {$resp}");
    return false;
  }

  return true;
}
