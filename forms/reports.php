<?php
// Simple reports page
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Require login
if (!isLoggedIn()) {
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'admin';

// If export=bookings, output CSV (admin and hospital only)
if (isset($_GET['export']) && $_GET['export'] === 'bookings') {
    if (!in_array($user_role, ['admin','hospital'])) {
        header('HTTP/1.1 403 Forbidden');
        echo "Forbidden\n";
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookings.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','parent_id','hospital_id','child_id','vaccine_type','booking_date','booking_time','status','notes','created_at']);

    $sql = 'SELECT id,parent_id,hospital_id,child_id,vaccine_type,booking_date,booking_time,status,notes,created_at FROM bookings ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// Get basic stats using existing helper if available
$stats = [];
if (function_exists('getDashboardStats')) {
    $stats = getDashboardStats($pdo, $user_id, $user_role);
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports | Medical</title>
    <style>
        body{font-family:Arial,Helvetica,sans-serif;padding:2rem;background:#f7f9fb}
        .card{background:#fff;padding:1rem;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.05);margin-bottom:1rem}
        .row{display:flex;gap:1rem}
        .col{flex:1}
        a.btn{display:inline-block;padding:.5rem .75rem;background:#0066ff;color:#fff;border-radius:6px;text-decoration:none}
    </style>
</head>
<body>
    <h1>Reports</h1>

    <div class="card">
        <h3>Quick Stats</h3>
        <div class="row">
            <div class="col"><strong>Parents:</strong> <?php echo htmlspecialchars($stats['total_parents'] ?? 'n/a'); ?></div>
            <div class="col"><strong>Hospitals:</strong> <?php echo htmlspecialchars($stats['total_hospitals'] ?? 'n/a'); ?></div>
            <div class="col"><strong>Bookings:</strong> <?php echo htmlspecialchars($stats['total_bookings'] ?? 'n/a'); ?></div>
        </div>
    </div>

    <div class="card">
        <h3>Export</h3>
        <p>Download CSV exports for offline analysis.</p>
        <?php if (in_array($user_role, ['admin','hospital'])): ?>
            <a class="btn" href="reports.php?export=bookings">Download Bookings CSV</a>
        <?php else: ?>
            <p>You do not have permission to download exports.</p>
        <?php endif; ?>
    </div>

</body>
</html>
