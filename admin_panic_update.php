<?php
require_once __DIR__ . "/require_admin.php";

$data = json_decode(file_get_contents("php://input"), true);
$id = (int)($data["id"] ?? 0);
$status = strtolower(trim($data["status"] ?? ""));

$allowed = ["new","ack","resolved"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Invalid payload"]);
  exit;
}

$stmt = $pdo->prepare("UPDATE panic_requests SET status = ? WHERE id = ?");
$stmt->execute([$status, $id]);

echo json_encode(["ok"=>true,"message"=>"Updated"]);