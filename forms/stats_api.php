<?php
session_start();
include 'connection.php';
include 'csrf.php';

if ($_server['request_method'] === 'post') {
    if (!csrf_verify($_post['csrf_token'] ?? '')) {
        die('invalid csrf token');
    }
}

header('Content-Type: application/json');

// Return aggregated stats: vaccinations per day (last 30 days)
$rows = [];
$q = $conn->query("
  SELECT DATE(administered_date) as day, COUNT(*) as vaccinations
  FROM vaccination_records
  WHERE administered_date IS NOT NULL
  GROUP BY DATE(administered_date)
  ORDER BY DATE(administered_date) DESC
  LIMIT 30
");
while ($r = $q->fetch_assoc()) {
    $rows[] = $r;
}
echo json_encode(['data' => array_reverse($rows)]);
