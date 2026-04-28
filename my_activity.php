<?php
require_once __DIR__ . "/auth_helpers.php";
require_once __DIR__ . "/db.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code,$payload){
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405,[
    "ok"=>false,
    "message"=>"Method not allowed"
  ]);
}

$token = bearer_token();

if ($token === "") {
  $token = trim($_GET["token"] ?? "");
}

if ($token === "") {
  out(401,[
    "ok"=>false,
    "message"=>"Missing token"
  ]);
}

try{

$user = auth_get_user_by_token($pdo,$token);

if(!$user){
  out(401,[
    "ok"=>false,
    "message"=>"Unauthorized"
  ]);
}

if(auth_check_token_expired($user)){
  out(401,[
    "ok"=>false,
    "message"=>"Token expired"
  ]);
}

if($user["role"]!=="citizen"){
  out(403,[
    "ok"=>false,
    "message"=>"Citizen access only."
  ]);
}


/* INCIDENT REPORTS */
$incidentStmt = $pdo->prepare("
SELECT
 id,
 incident_code,
 title,
 incident_type,
 verification_status,
 created_at,
 updated_at,
 severity_score
FROM incident_reports
WHERE reporter_user_id=?
ORDER BY created_at DESC
LIMIT 100
");

$incidentStmt->execute([
 (int)$user["id"]
]);

$incidents = $incidentStmt->fetchAll(PDO::FETCH_ASSOC);


/* PANIC REQUESTS */
$panicStmt = $pdo->prepare("
SELECT
 id,
 panic_code,
 level,
 status,
 created_at,
 updated_at
FROM panic_requests
WHERE user_id=?
ORDER BY created_at DESC
LIMIT 100
");

$panicStmt->execute([
 (int)$user["id"]
]);

$panics = $panicStmt->fetchAll(PDO::FETCH_ASSOC);


/* MERGED FEED */
$feed=[];

foreach($incidents as $r){

$status = strtoupper($r["verification_status"] ?? "PENDING");

$feed[]=[
 "type"=>"incident",
 "id"=>(int)$r["id"],
 "reference"=>$r["incident_code"],
 "title"=>$r["title"] ?: "Incident Report",
 "status"=>$status,
 "priority"=>$r["severity_score"] ?? null,
 "category"=>$r["incident_type"] ?? "Incident",
 "created_at"=>$r["created_at"],
 "updated_at"=>$r["updated_at"]
];
}

foreach($panics as $p){

$status = strtoupper($p["status"] ?? "NEW");

$feed[]=[
 "type"=>"panic",
 "id"=>(int)$p["id"],
 "reference"=>$p["panic_code"],
 "title"=>"Panic Request",
 "status"=>$status,
 "level"=>$p["level"],
 "created_at"=>$p["created_at"],
 "updated_at"=>$p["updated_at"]
];
}

usort($feed,function($a,$b){
 return strtotime($b["created_at"]) - strtotime($a["created_at"]);
});

out(200,[
 "ok"=>true,

 "summary"=>[
   "reports"=>count($incidents),
   "panic_requests"=>count($panics),
   "total"=>count($feed)
 ],

 "activities"=>$feed
]);

}
catch(Throwable $e){
 out(500,[
   "ok"=>false,
   "message"=>$e->getMessage()
 ]);
}