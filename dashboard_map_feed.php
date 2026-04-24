<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";
require_once __DIR__ . "/hotspot_lib.php";

header("Content-Type: application/json; charset=UTF-8");

try {

$scope = admin_scope_from_auth($pdo,$AUTH_USER);

$days=(int)($_GET["days"]??30);
if($days<1)$days=30;
if($days>365)$days=365;


/* ---------------- HOTSPOTS ---------------- */

$provinceFilter=null;
$cityFilter=null;
$hotspotRole=!empty($scope["is_global"])
 ? "super_admin"
 : "admin";

if(empty($scope["is_global"])){
   $provinceFilter=trim((string)($scope["station_province"]??""));
   $cityFilter=trim((string)($scope["station_city_municipality"]??""));
}

$hotspots=get_computed_hotspots(
   $pdo,
   $days,
   $provinceFilter ?: null,
   $cityFilter ?: null,
   $hotspotRole,
   null
);



/* ------------ PANIC QUEUE ------------ */

$panicParams=[];
$panicWhere="
WHERE p.status IN ('new','ack')
AND p.lat IS NOT NULL
AND p.lng IS NOT NULL
";

$panicWhere .= scope_where_clause(
 "p.province",
 $scope,
 $panicParams,
 ":panic_province"
);

$panicWhere .= scope_city_where_clause(
 "p.city_municipality",
 $scope,
 $panicParams,
 ":panic_city"
);

$stmt=$pdo->prepare("
SELECT
p.id,
p.level,
p.lat,
p.lng,
p.status,
p.created_at,
p.region,
p.province,
p.city_municipality,
p.barangay,
u.firstname,
u.lastname
FROM panic_requests p
JOIN users u ON u.id=p.user_id
$panicWhere
ORDER BY p.created_at DESC
LIMIT 200
");
$stmt->execute($panicParams);
$panic=$stmt->fetchAll(PDO::FETCH_ASSOC);



/* -------- VERIFIED INCIDENTS (SEVERITY HEATMAP) -------- */

$verifiedParams=[];

$verifiedWhere="
WHERE verification_status='VERIFIED'
AND incident_phase <> 'REJECTED'
AND lat IS NOT NULL
AND lng IS NOT NULL
";

$verifiedWhere .= scope_where_clause(
 "province",
 $scope,
 $verifiedParams,
 ":verified_province"
);

$verifiedWhere .= scope_city_where_clause(
 "city_municipality",
 $scope,
 $verifiedParams,
 ":verified_city"
);

/*
Join crime_types so severity_weight can be used
if incident.severity_score null.
*/

$stmt=$pdo->prepare("
SELECT
ir.id,
ir.title,
ir.incident_type,
ir.crime_category,
ir.lat,
ir.lng,
ir.region,
ir.barangay,
ir.city_municipality,
ir.province,
ir.date_reported,
ir.created_at,
ir.severity_score,
ct.severity_weight
FROM incident_reports ir
LEFT JOIN crime_types ct
ON UPPER(TRIM(ct.crime_name))
= UPPER(TRIM(ir.incident_type))
$verifiedWhere
ORDER BY ir.created_at DESC
LIMIT 1000
");

$stmt->execute($verifiedParams);
$verified=$stmt->fetchAll(PDO::FETCH_ASSOC);



/* -------- PENDING -------- */

$pendingParams=[];

$pendingWhere="
WHERE verification_status='PENDING'
AND incident_phase<>'REJECTED'
AND lat IS NOT NULL
AND lng IS NOT NULL
";

$pendingWhere .= scope_where_clause(
 "province",
 $scope,
 $pendingParams,
 ":pending_province"
);

$pendingWhere .= scope_city_where_clause(
 "city_municipality",
 $scope,
 $pendingParams,
 ":pending_city"
);

$stmt=$pdo->prepare("
SELECT
id,
incident_code,
title,
incident_type,
lat,
lng,
region,
barangay,
city_municipality,
province,
verification_status,
created_at
FROM incident_reports
$pendingWhere
ORDER BY created_at DESC
LIMIT 300
");

$stmt->execute($pendingParams);
$pending=$stmt->fetchAll(PDO::FETCH_ASSOC);



echo json_encode([
"ok"=>true,
"scope"=>$scope,
"filters"=>[
   "days"=>$days
],

/* HOTSPOTS */
"hotspots"=>$hotspots,



/* PANIC */
"panic_queue"=>array_map(function($r){

$name=trim(
 ((string)$r["firstname"])." ".
 ((string)$r["lastname"])
);

return[
"id"=>(int)$r["id"],
"level"=>$r["level"],
"lat"=>(float)$r["lat"],
"lng"=>(float)$r["lng"],
"status"=>$r["status"],
"created_at"=>$r["created_at"],
"user_name"=>$name,
"region"=>$r["region"],
"province"=>$r["province"],
"city_municipality"=>$r["city_municipality"],
"barangay"=>$r["barangay"]
];

},$panic),



/* VERIFIED HEATMAP POINTS WITH SEVERITY WEIGHT */
"verified_points"=>array_map(function($r){

$weight=
$r["severity_score"]
? (float)$r["severity_score"]
: (
   $r["severity_weight"]
   ? (float)$r["severity_weight"]
   : hotspot_crime_weight(
       $r["incident_type"],
       $r["crime_category"]
     )
  );

return[
"id"=>(int)$r["id"],
"title"=>$r["title"],
"incident_type"=>$r["incident_type"],
"crime_category"=>$r["crime_category"],
"severity_score"=>$weight,

/* THIS drives heatmap intensity */
"weight"=>$weight,

"lat"=>(float)$r["lat"],
"lng"=>(float)$r["lng"],
"region"=>$r["region"],
"barangay"=>$r["barangay"],
"city_municipality"=>$r["city_municipality"],
"province"=>$r["province"],
"date_reported"=>$r["date_reported"],
"created_at"=>$r["created_at"]
];

},$verified),



"pending_reports"=>array_map(function($r){

return[
"id"=>(int)$r["id"],
"incident_code"=>$r["incident_code"],
"title"=>$r["title"],
"incident_type"=>$r["incident_type"],
"lat"=>(float)$r["lat"],
"lng"=>(float)$r["lng"],
"region"=>$r["region"],
"barangay"=>$r["barangay"],
"city_municipality"=>$r["city_municipality"],
"province"=>$r["province"],
"verification_status"=>$r["verification_status"],
"created_at"=>$r["created_at"]
];

},$pending)

]);

}
catch(Throwable $e){

http_response_code(500);

echo json_encode([
 "ok"=>false,
 "message"=>$e->getMessage(),
 "file"=>basename(__FILE__),
 "line"=>$e->getLine()
]);

}