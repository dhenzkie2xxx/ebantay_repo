<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";
require_once __DIR__ . "/admin_scope_helpers.php";

header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload){
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

function is_valid_date_ymd($date){
  if(!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map("intval", explode("-", $date));
  return checkdate($m, $d, $y);
}

try{

$scope = admin_scope_from_auth($pdo,$AUTH_USER);

/*
|--------------------------------------------------------------------------
| FILTER MODE
|--------------------------------------------------------------------------
| Dashboard still works with:
|   ?days=365
|
| Data Analytics works with:
|   ?mode=year&year=2026
|   ?mode=custom&from=2026-01-01&to=2026-05-06
|--------------------------------------------------------------------------
*/

$mode = $_GET["mode"] ?? "";
$year = $_GET["year"] ?? "";
$from = $_GET["from"] ?? "";
$to = $_GET["to"] ?? "";

$days = (int)($_GET["days"] ?? 30);

if($days < 7) $days = 30;
if($days > 365) $days = 365;

$params = [];

$dateExpr = "COALESCE(date_reported, created_at)";
$periodLabel = "";
$filterMode = "days";

$where = "
WHERE verification_status='VERIFIED'
";

if($mode === "year" && preg_match('/^\d{4}$/', (string)$year)){
  $filterMode = "year";
  $year = (int)$year;

  $where .= "
  AND $dateExpr >= :year_start
  AND $dateExpr < :year_end
  ";

  $params[":year_start"] = $year . "-01-01 00:00:00";
  $params[":year_end"] = ($year + 1) . "-01-01 00:00:00";
  $periodLabel = "Year " . $year;

}else if($mode === "custom" && is_valid_date_ymd($from) && is_valid_date_ymd($to)){
  $filterMode = "custom";

  if(strtotime($from) > strtotime($to)){
    out(400,[
      "ok"=>false,
      "message"=>"Invalid date range. From date must not be later than To date."
    ]);
  }

  $where .= "
  AND $dateExpr >= :date_from
  AND $dateExpr < DATE_ADD(:date_to, INTERVAL 1 DAY)
  ";

  $params[":date_from"] = $from . " 00:00:00";
  $params[":date_to"] = $to . " 00:00:00";
  $periodLabel = $from . " to " . $to;

}else{
  /*
  |--------------------------------------------------------------------------
  | DEFAULT DASHBOARD BEHAVIOR
  |--------------------------------------------------------------------------
  | Do not break Dashboard.jsx. If no mode is provided, it still uses days.
  |--------------------------------------------------------------------------
  */

  $where .= "
  AND $dateExpr >= (UTC_TIMESTAMP() - INTERVAL :days DAY)
  ";

  $params[":days"] = $days;
  $periodLabel = "Last " . $days . " days";
}

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
  "scope"=>$scope,
  "filter_mode"=>$filterMode,
  "period_label"=>$periodLabel,
  "total"=>0,
  "days"=>$filterMode === "days" ? $days : null,
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
COALESCE(NULLIF(incident_type,''),'Unknown') incident_type,
COUNT(*) n_i,
ROUND(COUNT(*)/:N,4) rel_freq
FROM incident_reports
$where
GROUP BY COALESCE(NULLIF(incident_type,''),'Unknown')
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
COALESCE(NULLIF(barangay,''),'Unknown') barangay,
COALESCE(NULLIF(incident_type,''),'Unknown') incident_type,
COUNT(*) cnt,
ROW_NUMBER() OVER(
 PARTITION BY COALESCE(NULLIF(barangay,''),'Unknown')
 ORDER BY COUNT(*) DESC
) rn
FROM incident_reports
$where
GROUP BY
COALESCE(NULLIF(barangay,''),'Unknown'),
COALESCE(NULLIF(incident_type,''),'Unknown')
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
 "incident_type"=>$row["incident_type"] ?: "Unknown",
 "count"=>(int)$row["cnt"]
];

}


/* -----------------------------------
3. HOURLY INCIDENT FREQUENCY
----------------------------------- */

$stmt=$pdo->prepare("
SELECT
COALESCE(incident_hour, HOUR($dateExpr)) hr,
COUNT(*) c
FROM incident_reports
$where
GROUP BY COALESCE(incident_hour, HOUR($dateExpr))
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
COALESCE(incident_weekday, WEEKDAY($dateExpr)) wd,
COUNT(*) c
FROM incident_reports
$where
GROUP BY COALESCE(incident_weekday, WEEKDAY($dateExpr))
ORDER BY wd
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

"filter_mode"=>$filterMode,
"period_label"=>$periodLabel,

"formula"=>"fi=ni/N",

"total"=>$N,

"days"=>$filterMode === "days" ? $days : null,

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