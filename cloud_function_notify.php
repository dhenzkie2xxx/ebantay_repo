<?php
function send_push_via_cloud_function(array $tokens, string $title, string $body, array $data = []): array {
  $url = getenv("HOTSPOT_FUNCTION_URL") ?: "";
  $secret = getenv("HOTSPOT_FUNCTION_SECRET") ?: "";

  $tokens = array_values(array_filter(array_unique(array_map("trim", $tokens))));

  if ($url === "" || $secret === "" || empty($tokens)) {
    return [
      "ok" => false,
      "message" => "Cloud Function config missing or no tokens"
    ];
  }

  $payload = json_encode([
    "secret" => $secret,
    "tokens" => $tokens,
    "title" => $title,
    "body" => $body,
    "data" => $data,
  ]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Accept: application/json",
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 20,
  ]);

  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false) {
    return [
      "ok" => false,
      "message" => "curl error: " . $err
    ];
  }

  $json = json_decode($raw, true);

  return [
    "ok" => ($status >= 200 && $status < 300),
    "status" => $status,
    "response" => $json ?: $raw,
  ];
}