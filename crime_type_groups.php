<?php
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

function out($code, $payload) {
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  out(405, ["ok" => false, "message" => "Method not allowed"]);
}

try {
  $stmt = $pdo->query("
    SELECT
      crime_category,
      COUNT(*) AS total
    FROM crime_types
    WHERE is_active = 1
    GROUP BY crime_category
    ORDER BY crime_category ASC
  ");

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $groups = [
    [
      "key" => "All",
      "label" => "All",
      "count" => 0
    ]
  ];

  $totalAll = 0;

  foreach ($rows as $r) {
    $count = (int)$r["total"];
    $totalAll += $count;

    $label = $r["crime_category"];
    if ($label === "INDEX") $label = "Index";
    else if ($label === "NON_INDEX") $label = "Non-Index";
    else if ($label === "SPECIAL_LAW") $label = "Special Law";
    else if ($label === "OTHER") $label = "Other";

    $groups[] = [
      "key" => $r["crime_category"],
      "label" => $label,
      "count" => $count
    ];
  }

  $groups[0]["count"] = $totalAll;

  // optional static Panic chip
  $groups[] = [
    "key" => "Panic",
    "label" => "Panic",
    "count" => null
  ];

  out(200, [
    "ok" => true,
    "groups" => $groups
  ]);
} catch (Throwable $e) {
  out(500, [
    "ok" => false,
    "message" => "Server error",
    "debug" => $e->getMessage()
  ]);
}