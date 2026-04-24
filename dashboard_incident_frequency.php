<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload){
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

try{

$scope = admin_scope_from_auth($pdo,$AUTH_USER);

$days = (int)($_GET["days"] ?? 30);

if($days < 7) $days = 30;
if($days > 365) $days = 365;

$params = [
 ":days"=>$days
];

$where = "
WHERE verification_status='VERIFIED'
AND COALESCE(date_reported,created_at)
>= (UTC_TIMESTAMP() - INTERVAL :days DAY)
";

$where .= scope_where_clause(
 "province",
 $scope,
 $params,
 ":scope_province"
);

$where .= scope_city_where_clause(
 "city_municipality",
 $scope,
 $params,
 ":scope_city"
);

/* -------------------------------
TOTAL INCIDENTS N
-------------------------------- */

$totalStmt = $pdo->prepare("
SELECT COUNT(*) total
FROM incident_reports
$where
");

$totalStmt->execute($params);

$N=(int)$totalStmt->fetchColumn();

if($N<=0){
 out(200,[
  "ok"=>true,
  "total"=>0,
  "formula"=>"fi=ni/N",
  "by_type"=>[],
  "barangay_patterns"=>[],
  "hourly"=>[],
  "weekday"=>[],
  "top_crime"=>null,
  "summary"=>"No verified incidents found."
 ]);
}


/* -----------------------------------
1. INCIDENT FREQUENCY BY TYPE
fi = ni / N
----------------------------------- */

$typeParams = $params;
$typeParams[":N"]=$N;

$stmt=$pdo->prepare("
SELECT
incident_type,
COUNT(*) n_i,
ROUND(COUNT(*)/:N,4) rel_freq
FROM incident_reports
$where
GROUP BY incident_type
ORDER BY n_i DESC
LIMIT 10
");

$stmt->execute($typeParams);

$byType=[];

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){

$byType[]=[
 "incident_type"=>$row["incident_type"],
 "count"=>(int)$row["n_i"],
 "relative_frequency"=>(float)$row["rel_freq"],
 "percent"=>round(
   ((float)$row["rel_freq"])*100,
   2
 )
];

}


/* -----------------------------------
2. MOST COMMON CRIME PER BARANGAY
----------------------------------- */

$stmt=$pdo->prepare("
SELECT ranked.barangay,
       ranked.incident_type,
       ranked.cnt
FROM (
SELECT
barangay,
incident_type,
COUNT(*) cnt,
ROW_NUMBER() OVER(
 PARTITION BY barangay
 ORDER BY COUNT(*) DESC
) rn
FROM incident_reports
$where
GROUP BY barangay,incident_type
) ranked
WHERE rn=1
ORDER BY cnt DESC
LIMIT 10
");

$stmt->execute($params);

$barangayPatterns=[];

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){

$barangayPatterns[]=[
 "barangay"=>$row["barangay"] ?: "Unknown",
 "incident_type"=>$row["incident_type"],
 "count"=>(int)$row["cnt"]
];

}


/* -----------------------------------
3. HOURLY INCIDENT FREQUENCY
----------------------------------- */

$stmt=$pdo->prepare("
SELECT
incident_hour hr,
COUNT(*) c
FROM incident_reports
$where
GROUP BY incident_hour
ORDER BY hr
");

$stmt->execute($params);

$hourly=[];

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){

$hourly[]=[
 "hour"=>(int)$row["hr"],
 "count"=>(int)$row["c"]
];

}


/* -----------------------------------
4. WEEKDAY FREQUENCY
----------------------------------- */

$weekMap=[
0=>"Monday",
1=>"Tuesday",
2=>"Wednesday",
3=>"Thursday",
4=>"Friday",
5=>"Saturday",
6=>"Sunday"
];

$stmt=$pdo->prepare("
SELECT
incident_weekday wd,
COUNT(*) c
FROM incident_reports
$where
GROUP BY incident_weekday
ORDER BY incident_weekday
");

$stmt->execute($params);

$weekday=[];

while($row=$stmt->fetch(PDO::FETCH_ASSOC)){

$w=(int)$row["wd"];

$weekday[]=[
 "weekday"=>$weekMap[$w] ?? "Unknown",
 "count"=>(int)$row["c"]
];

}


/* -----------------------------------
AUTO SUMMARY
----------------------------------- */

$summary="";

if(!empty($byType)){

$topCrime=$byType[0];

$topBarangay=
 !empty($barangayPatterns)
 ? $barangayPatterns[0]["barangay"]
 : "multiple barangays";

$summary=
$topCrime["incident_type"] .
" represents " .
$topCrime["percent"] .
"% of verified incidents and is the most common reported crime, with concentration observed in " .
$topBarangay .
" during the selected analysis period.";

}else{
$topCrime=null;
$summary="No dominant crime pattern detected.";
}

out(200,[

"ok"=>true,
"scope"=>$scope,

"formula"=>"fi=ni/N",

"total"=>$N,

"days"=>$days,

"by_type"=>$byType,

"barangay_patterns"=>$barangayPatterns,

"hourly"=>$hourly,

"weekday"=>$weekday,

"top_crime"=>$byType[0] ?? null,

"summary"=>$summary

]);

}
catch(Throwable $e){

out(500,[

"ok"=>false,
"message"=>$e->getMessage(),
"file"=>basename(__FILE__),
"line"=>$e->getLine()

]);

}