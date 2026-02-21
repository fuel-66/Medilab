<?php
session_start();
include 'connection.php';
include 'csrf.php';

// Require admin login
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';
$feedback = "";

/* ================================================================
   HANDLE ADMIN ACTIONS (approve, cancel, delete bookings)
================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $feedback = "Invalid CSRF token";
    } else {
        $booking_id = (int)($_POST['booking_id'] ?? 0);

        if (isset($_POST['approve_booking']) && $booking_id) {
            $stmt = $conn->prepare("UPDATE bookings SET status='approved' WHERE id=?");
            $stmt->bind_param("i", $booking_id);
            if ($stmt->execute()) {
                $feedback = "Booking approved!";
                error_log("dashboard.php: Admin $admin_id approved booking $booking_id");
            } else {
                $feedback = "Error: " . $stmt->error;
            }
            $stmt->close();
        }

        if (isset($_POST['cancel_booking']) && $booking_id) {
            $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE id=?");
            $stmt->bind_param("i", $booking_id);
            if ($stmt->execute()) {
                $feedback = "Booking cancelled!";
                error_log("dashboard.php: Admin $admin_id cancelled booking $booking_id");
            } else {
                $feedback = "Error: " . $stmt->error;
            }
            $stmt->close();
        }

        if (isset($_POST['delete_booking']) && $booking_id) {
            $stmt = $conn->prepare("DELETE FROM bookings WHERE id=?");
            $stmt->bind_param("i", $booking_id);
            if ($stmt->execute()) {
                $feedback = "Booking deleted!";
                error_log("dashboard.php: Admin $admin_id deleted booking $booking_id");
            } else {
                $feedback = "Error: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

/* ================================================================
   FETCH REAL DATA FROM DATABASE
================================================================ */

// Total counts
$total_parents = (int)$conn->query("SELECT COUNT(*) AS c FROM parents")->fetch_assoc()['c'];
$total_hospitals = (int)$conn->query("SELECT COUNT(*) AS c FROM hospitals")->fetch_assoc()['c'];
$total_bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings")->fetch_assoc()['c'];
$approved_bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='approved'")->fetch_assoc()['c'];
$pending_bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='pending'")->fetch_assoc()['c'];
$completed_bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='completed'")->fetch_assoc()['c'];
$cancelled_bookings = (int)$conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status='cancelled'")->fetch_assoc()['c'];
$total_vaccines = (int)$conn->query("SELECT COUNT(*) AS c FROM vaccines")->fetch_assoc()['c'];

// Fetch all bookings with parent and hospital info
$bookings_result = $conn->query("
    SELECT b.id, b.vaccine_type, b.booking_date, b.booking_time, b.status,
           p.name AS parent_name, h.name AS hospital_name,
           c.name AS child_name
    FROM bookings b
    LEFT JOIN parents p ON b.parent_id = p.id
    LEFT JOIN hospitals h ON b.hospital_id = h.id
    LEFT JOIN children c ON b.child_id = c.id
    ORDER BY b.created_at DESC
    LIMIT 100
");
$bookings = [];
if ($bookings_result) {
    $bookings = $bookings_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch all parents
$parents_result = $conn->query("SELECT id, name, email, phone FROM parents ORDER BY name LIMIT 50");
$parents = [];
if ($parents_result) {
    $parents = $parents_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch all hospitals
$hospitals_result = $conn->query("SELECT id, name, email, phone FROM hospitals ORDER BY name LIMIT 50");
$hospitals = [];
if ($hospitals_result) {
    $hospitals = $hospitals_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch vaccine stats with completion rates
$vaccine_stats = [];
$vax_result = $conn->query("
    SELECT vaccine_type, COUNT(*) AS total,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
           SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending
    FROM bookings
    WHERE vaccine_type IS NOT NULL
    GROUP BY vaccine_type
    ORDER BY total DESC
");
if ($vax_result) {
    $vaccine_stats = $vax_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch booking trends (last 7 days)
$booking_trends = [];
$trend_result = $conn->query("
    SELECT DATE(booking_date) AS booking_day, COUNT(*) AS bookings_count,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
    FROM bookings
    WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(booking_date)
    ORDER BY booking_day ASC
");
if ($trend_result) {
    $booking_trends = $trend_result->fetch_all(MYSQLI_ASSOC);
}

// Monthly analytics
$monthly_analytics = [];
$monthly_result = $conn->query("
    SELECT MONTH(booking_date) AS month, YEAR(booking_date) AS year,
           COUNT(*) AS total_bookings,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_bookings,
           SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_bookings
    FROM bookings
    WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY YEAR(booking_date), MONTH(booking_date)
    ORDER BY year DESC, month DESC
    LIMIT 12
");
if ($monthly_result) {
    $monthly_analytics = $monthly_result->fetch_all(MYSQLI_ASSOC);
}

// Fetch all users (parents, hospitals, admins)
$all_users = [];
$admins = $conn->query("SELECT 'admin' as user_type, id, name, email, phone, created_at FROM admins ORDER BY created_at DESC") ?: new mysqli_result($conn);
$parent_result = $conn->query("SELECT 'parent' as user_type, id, name, email, phone, created_at FROM parents ORDER BY created_at DESC");
$hospital_result = $conn->query("SELECT 'hospital' as user_type, id, name, email, phone, created_at FROM hospitals ORDER BY created_at DESC");

if ($admins && $admins->num_rows > 0) {
    while ($admin = $admins->fetch_assoc()) {
        $all_users[] = $admin;
    }
}
if ($parent_result && $parent_result->num_rows > 0) {
    while ($parent = $parent_result->fetch_assoc()) {
        $all_users[] = $parent;
    }
}
if ($hospital_result && $hospital_result->num_rows > 0) {
    while ($hospital = $hospital_result->fetch_assoc()) {
        $all_users[] = $hospital;
    }
}

// Prepare monthly data for chart
$chart_months = [];
$chart_totals = [];
$chart_completed = [];
$chart_approved = [];
if (!empty($monthly_analytics)) {
    foreach (array_reverse($monthly_analytics) as $month) {
        $month_name = date('M Y', mktime(0, 0, 0, $month['month'], 1, $month['year']));
        $chart_months[] = $month_name;
        $chart_totals[] = $month['total_bookings'];
        $chart_completed[] = $month['completed_bookings'];
        $chart_approved[] = $month['approved_bookings'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Medical</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <style>
        :root {
            --primary: #007FFF;
            --primary-light: #3BA5FF;
            --primary-dark: #0052CC;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
            --info: #06B6D4;
            --dark: #0F172A;
            --gray-dark: #475569;
            --gray: #64748B;
            --gray-light: #E2E8F0;
            --light: #F8FAFC;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container { max-width: 1600px; margin: 0 auto; padding: 2rem; }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: var(--white);
            padding: 2.5rem;
            border-radius: 20px;
            margin-bottom: 2.5rem;
            box-shadow: var(--shadow-xl);
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 1rem;
            opacity: 0.95;
            font-weight: 300;
        }

        .header-right {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .user-info { text-align: right; }
        .user-name { font-weight: 700; font-size: 1.1rem; display: block; }
        .user-role { font-size: 0.9rem; opacity: 0.9; display: block; margin-top: 0.25rem; }

        /* Buttons */
        .btn {
            padding: 0.65rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: var(--white);
            color: var(--primary);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: #f0f4f8;
        }

        .btn-success {
            background: var(--success);
            color: white;
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
        }

        .btn-success:hover { background: #059669; transform: translateY(-1px); }

        .btn-warning {
            background: var(--warning);
            color: white;
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
        }

        .btn-warning:hover { background: #D97706; transform: translateY(-1px); }

        .btn-error {
            background: var(--error);
            color: white;
            font-size: 0.85rem;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
        }

        .btn-error:hover { background: #DC2626; transform: translateY(-1px); }

        /* Feedback */
        .feedback {
            padding: 1.25rem;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));
            border-left: 4px solid var(--success);
            border-radius: 12px;
            margin-bottom: 2rem;
            font-weight: 500;
            color: #047857;
            box-shadow: var(--shadow-sm);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--white);
            padding: 2rem;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-icon.primary { background: rgba(0, 127, 255, 0.1); color: var(--primary); }
        .stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.error { background: rgba(239, 68, 68, 0.1); color: var(--error); }
        .stat-icon.info { background: rgba(6, 182, 212, 0.1); color: var(--info); }

        .stat-label { font-size: 0.95rem; color: var(--gray); margin-bottom: 0.5rem; font-weight: 500; }
        .stat-value { font-size: 2.5rem; font-weight: 900; color: var(--primary); line-height: 1; margin-bottom: 0.5rem; }
        .stat-change { font-size: 0.85rem; font-weight: 600; margin-top: 0.75rem; }
        .stat-change.positive { color: var(--success); }
        .stat-change.negative { color: var(--error); }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin-bottom: 2.5rem;
        }

       

        .chart-card {
            background: var(--white);
            padding: 2rem;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title { font-size: 1.25rem; font-weight: 700; color: var(--dark); }
        .chart-container { height: 300px; position: relative; }

        /* Card */
        .card {
            background: var(--white);
            padding: 2rem;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-light);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--gray-light);
        }

        .card-title { font-size: 1.5rem; font-weight: 700; color: var(--dark); }
        .card-badge { background: var(--primary); color: white; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }

        /* Table */
        .table-container { overflow-x: auto; border-radius: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        th {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        td { padding: 1rem; border-bottom: 1px solid var(--gray-light); color: var(--gray-dark); }
        tr { transition: background 0.2s ease; }
        tbody tr:hover { background: var(--light); }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.9rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-pending { background: rgba(245, 158, 11, 0.15); color: #B45309; }
        .status-approved { background: rgba(16, 185, 129, 0.15); color: #047857; }
        .status-completed { background: rgba(0, 127, 255, 0.15); color: #0052CC; }
        .status-cancelled { background: rgba(239, 68, 68, 0.15); color: #B91C1C; }

        /* Actions */
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
        form { display: inline; }

        /* Empty State */
        .empty-state { text-align: center; padding: 3rem 2rem; color: var(--gray); }
        .empty-state-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        /* Responsive */
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; text-align: center; gap: 1.5rem; }
            .header h1 { font-size: 1.75rem; }
            .header-right { flex-direction: column; width: 100%; }
            .stats-grid { grid-template-columns: 1fr; }
            .charts-grid { grid-template-columns: 1fr; }
            .table-container { font-size: 0.85rem; }
            td, th { padding: 0.75rem; }
        }
    </style>
    <div class="container">
        <!-- Premium Header -->
        <div class="header">
            <div>
                <h1>📊 Admin Dashboard</h1>
                <p>Welcome back, manage your vaccination system efficiently</p>
            </div>
            <div class="header-right">
                <div class="user-info">
                    <span class="user-name"><?php echo $admin_name; ?></span>
                    <span class="user-role">Administrator</span>
                </div>
                <a href="logout.php" class="btn btn-primary">Logout</a>
            </div>
        </div>

        <!-- Feedback Message -->
        <?php if ($feedback): ?>
        <div class="feedback">✓ <?php echo htmlspecialchars($feedback); ?></div>
        <?php endif; ?>

        <!-- Quick Navigation -->
        <div style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
            <a href="admin_payments.php" class="btn btn-primary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none;">💰 Payments & Revenue</a>
            <a href="admin_vaccines.php" class="btn btn-primary" style="text-decoration: none;">💉 Vaccine Management</a>
            <a href="hospitals.php" class="btn btn-primary" style="text-decoration: none;">🏥 Hospitals Analytics</a>
            <a href="parents.php" class="btn btn-primary" style="text-decoration: none;">👥 Parents Analytics</a>
        </div>

        <!-- Premium Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">👥 Total Parents</div>
                        <div class="stat-value"><?php echo $total_parents; ?></div>
                    </div>
                    <div class="stat-icon primary">👨‍👩‍👧</div>
                </div>
                <div class="stat-change positive">↗ Active registrations</div>
            </div>

            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">🏥 Hospitals</div>
                        <div class="stat-value"><?php echo $total_hospitals; ?></div>
                    </div>
                    <div class="stat-icon success">🏢</div>
                </div>
                <div class="stat-change positive">↗ Partner networks</div>
            </div>

            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">📅 Total Bookings</div>
                        <div class="stat-value"><?php echo $total_bookings; ?></div>
                    </div>
                    <div class="stat-icon info">📋</div>
                </div>
                <div class="stat-change positive">↗ All appointments</div>
            </div>

            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">⏳ Pending</div>
                        <div class="stat-value"><?php echo $pending_bookings; ?></div>
                    </div>
                    <div class="stat-icon warning">⌛</div>
                </div>
                <div class="stat-change negative">↘ Need approval</div>
            </div>

            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">✅ Approved</div>
                        <div class="stat-value"><?php echo $approved_bookings; ?></div>
                    </div>
                    <div class="stat-icon success">✓</div>
                </div>
                <div class="stat-change positive">↗ Confirmed</div>
            </div>

            <div class="stat-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <div class="stat-label">🎉 Completed</div>
                        <div class="stat-value"><?php echo $completed_bookings; ?></div>
                    </div>
                    <div class="stat-icon info">🏁</div>
                </div>
                <div class="stat-change positive">↗ Finished</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">📈 Booking Status Distribution</div>
                        <p style="font-size: 0.9rem; color: var(--gray); margin-top: 0.25rem;">Overview of all booking statuses</p>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="bookingChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <div class="chart-title">💉 Quick Stats</div>
                </div>
                <div style="padding: 1rem 0;">
                    <div style="display: flex; justify-content: space-between; padding: 1rem 0; border-bottom: 1px solid var(--gray-light);">
                        <span style="color: var(--gray);">Total Vaccines</span>
                        <strong style="font-size: 1.5rem; color: var(--primary);"><?php echo $total_vaccines; ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 1rem 0; border-bottom: 1px solid var(--gray-light);">
                        <span style="color: var(--gray);">Cancelled</span>
                        <strong style="font-size: 1.5rem; color: var(--error);"><?php echo $cancelled_bookings; ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 1rem 0;">
                        <span style="color: var(--gray);">Success Rate</span>
                        <strong style="font-size: 1.5rem; color: var(--success);"><?php echo ($total_bookings > 0 ? round(($completed_bookings + $approved_bookings) / $total_bookings * 100) : 0); ?>%</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vaccinations Analytics Section -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">💉 Vaccination Analytics</div>
                <span class="card-badge"><?php echo count($vaccine_stats); ?> vaccines</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Vaccine Type</th>
                            <th>Total Bookings</th>
                            <th>✅ Completed</th>
                            <th>🔄 Approved</th>
                            <th>⏳ Pending</th>
                            <th>Success Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vaccine_stats)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--gray);">
                                <div class="empty-state">
                                    <div class="empty-state-icon">📊</div>
                                    <p>No vaccination data available</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($vaccine_stats as $vaccine): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($vaccine['vaccine_type']); ?></strong></td>
                            <td><strong><?php echo $vaccine['total']; ?></strong></td>
                            <td>
                                <span class="status-badge status-completed">
                                    <?php echo $vaccine['completed']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-approved">
                                    <?php echo $vaccine['approved']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-pending">
                                    <?php echo $vaccine['pending']; ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: var(--success);">
                                    <?php echo ($vaccine['total'] > 0 ? round($vaccine['completed'] / $vaccine['total'] * 100) : 0); ?>%
                                </strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Monthly Analytics Chart -->
        <div class="chart-card" style="margin-bottom: 2rem;">
            <div class="chart-header">
                <div>
                    <div class="chart-title">📊 Monthly Booking Trends</div>
                    <p style="font-size: 0.9rem; color: var(--gray); margin-top: 0.25rem;">Bookings, Completed, and Approved trends</p>
                </div>
            </div>
            <div class="chart-container" style="height: 350px;">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <!-- Monthly Analytics Section -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📈 Monthly Analytics</div>
                <span class="card-badge">Last 12 months</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Bookings</th>
                            <th>✅ Completed</th>
                            <th>🔄 Approved</th>
                            <th>Completion Rate</th>
                            <th>Growth</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthly_analytics)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--gray);">
                                <div class="empty-state">
                                    <div class="empty-state-icon">📉</div>
                                    <p>No monthly analytics available</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php 
                        $prev_total = 0;
                        foreach ($monthly_analytics as $month): 
                            $month_name = date('M Y', mktime(0, 0, 0, $month['month'], 1, $month['year']));
                            $growth = ($prev_total > 0) ? round((($month['total_bookings'] - $prev_total) / $prev_total) * 100) : 0;
                            $completion_rate = ($month['total_bookings'] > 0) ? round($month['completed_bookings'] / $month['total_bookings'] * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo $month_name; ?></strong></td>
                            <td><?php echo $month['total_bookings']; ?></td>
                            <td>
                                <span class="status-badge status-completed">
                                    <?php echo $month['completed_bookings']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-approved">
                                    <?php echo $month['approved_bookings']; ?>
                                </span>
                            </td>
                            <td><strong style="color: var(--success);"><?php echo $completion_rate; ?>%</strong></td>
                            <td>
                                <?php if ($growth > 0): ?>
                                    <span style="color: var(--success); font-weight: 600;">↗ +<?php echo $growth; ?>%</span>
                                <?php elseif ($growth < 0): ?>
                                    <span style="color: var(--error); font-weight: 600;">↘ <?php echo $growth; ?>%</span>
                                <?php else: ?>
                                    <span style="color: var(--gray);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php 
                            $prev_total = $month['total_bookings'];
                        endforeach; 
                        ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Bookings Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">📋 Recent Bookings</div>
                <span class="card-badge"><?php echo count($bookings); ?> total</span>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>#ID</th>
                            <th>👨‍👩‍👧 Parent</th>
                            <th>🏥 Hospital</th>
                            <th>👶 Child</th>
                            <th>💉 Vaccine</th>
                            <th>📅 Date</th>
                            <th>Status</th>
                            <th>⚙️ Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: var(--gray);">
                                <div class="empty-state">
                                    <div class="empty-state-icon">📭</div>
                                    <p>No bookings found yet</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td><strong>#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo htmlspecialchars($booking['parent_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($booking['hospital_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($booking['child_name'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo htmlspecialchars($booking['vaccine_type'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo $booking['booking_date'] ?? 'N/A'; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($booking['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <button type="submit" name="approve_booking" class="btn btn-success" title="Approve this booking">✓</button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <button type="submit" name="cancel_booking" class="btn btn-warning" title="Cancel this booking">⊘</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline;" onclick="return confirm('Delete this booking?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <button type="submit" name="delete_booking" class="btn btn-error" title="Delete this booking">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Two Column Layout for Parents and Hospitals -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
            <!-- Parents Table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">👥 Registered Parents</div>
                    <span class="card-badge"><?php echo count($parents); ?></span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($parents)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 1.5rem; color: var(--gray);">No parents found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach (array_slice($parents, 0, 5) as $parent): ?>
                            <tr>
                                <td><strong>#<?php echo $parent['id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($parent['name']); ?></td>
                                <td style="font-size: 0.9rem;"><?php echo htmlspecialchars($parent['email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($parent['phone'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Hospitals Table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">🏥 Partner Hospitals</div>
                    <span class="card-badge"><?php echo count($hospitals); ?></span>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($hospitals)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 1.5rem; color: var(--gray);">No hospitals found</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach (array_slice($hospitals, 0, 5) as $hospital): ?>
                            <tr>
                                <td><strong>#<?php echo $hospital['id']; ?></strong></td>
                                <td><?php echo htmlspecialchars($hospital['name']); ?></td>
                                <td style="font-size: 0.9rem;"><?php echo htmlspecialchars($hospital['email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($hospital['phone'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- User Profiles Section -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">👥 System Users & Staff</div>
                <span class="card-badge"><?php echo count($all_users); ?> total</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; padding: 1.5rem 0;">
                <?php if (empty($all_users)): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 2rem; color: var(--gray);">
                        <div class="empty-state">
                            <div class="empty-state-icon">👥</div>
                            <p>No users found</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_users as $user): ?>
                    <div style="background: var(--light); border: 1px solid var(--gray-light); border-radius: 16px; padding: 1.5rem; transition: all 0.3s ease;" 
                         onmouseover="this.style.boxShadow='var(--shadow-lg)'; this.style.transform='translateY(-4px)'" 
                         onmouseout="this.style.boxShadow=''; this.style.transform='translateY(0)'">
                        
                        <!-- User Type Badge -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <div>
                                <?php 
                                $user_icon = '👤';
                                $user_color = '#007FFF';
                                $type_label = 'Parent';
                                if ($user['user_type'] === 'admin') {
                                    $user_icon = '👨‍💼';
                                    $user_color = '#EF4444';
                                    $type_label = 'Admin';
                                } elseif ($user['user_type'] === 'hospital') {
                                    $user_icon = '🏥';
                                    $user_color = '#10B981';
                                    $type_label = 'Hospital';
                                }
                                ?>
                                <span style="font-size: 2rem;"><?php echo $user_icon; ?></span>
                            </div>
                            <span class="status-badge" style="background: rgba(<?php 
                                if ($user['user_type'] === 'admin') echo '239, 68, 68';
                                elseif ($user['user_type'] === 'hospital') echo '16, 185, 129';
                                else echo '0, 127, 255';
                            ?>, 0.15); color: <?php echo $user_color; ?>">
                                <?php echo $type_label; ?>
                            </span>
                        </div>

                        <!-- User Name -->
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--dark); margin-bottom: 0.5rem;">
                            <?php echo htmlspecialchars($user['name']); ?>
                        </h3>

                        <!-- User Details -->
                        <div style="font-size: 0.9rem; color: var(--gray); margin-bottom: 1rem; line-height: 1.8;">
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <span>✉️</span>
                                <span style="word-break: break-all;"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <span>📞</span>
                                <span><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span>📅</span>
                                <span><?php echo $user['created_at'] ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?></span>
                            </div>
                        </div>

                        <!-- Status Indicator -->
                        <div style="padding-top: 1rem; border-top: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.85rem; font-weight: 600; color: var(--success);">
                                ● Active
                            </span>
                            <span style="font-size: 0.85rem; color: var(--gray);">
                                ID: #<?php echo $user['id']; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="text-align: center; padding: 2rem; color: var(--gray); font-size: 0.9rem;">
            💚 VaxCare Pro Admin Dashboard | © 2025 | All rights reserved
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Trends Chart
            const monthlyCtx = document.getElementById('monthlyChart');
            if (monthlyCtx) {
                const months = <?php echo json_encode($chart_months); ?>;
                const totals = <?php echo json_encode($chart_totals); ?>;
                const completed = <?php echo json_encode($chart_completed); ?>;
                const approved = <?php echo json_encode($chart_approved); ?>;

                new Chart(monthlyCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [
                            {
                                label: 'Total Bookings',
                                data: totals,
                                borderColor: '#007FFF',
                                backgroundColor: 'rgba(0, 127, 255, 0.1)',
                                tension: 0.4,
                                fill: true,
                                pointBackgroundColor: '#007FFF',
                                pointBorderColor: '#FFFFFF',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            },
                            {
                                label: 'Completed',
                                data: completed,
                                borderColor: '#10B981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                tension: 0.4,
                                fill: false,
                                pointBackgroundColor: '#10B981',
                                pointBorderColor: '#FFFFFF',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            },
                            {
                                label: 'Approved',
                                data: approved,
                                borderColor: '#F59E0B',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                tension: 0.4,
                                fill: false,
                                pointBackgroundColor: '#F59E0B',
                                pointBorderColor: '#FFFFFF',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    font: { size: 14, weight: 600 },
                                    padding: 20,
                                    usePointStyle: true,
                                    boxWidth: 12
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                padding: 12,
                                titleFont: { size: 14, weight: 600 },
                                bodyFont: { size: 13 },
                                borderColor: 'rgba(226, 232, 240, 0.3)',
                                borderWidth: 1
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(226, 232, 240, 0.3)',
                                    drawBorder: false
                                },
                                ticks: {
                                    font: { size: 12 },
                                    color: '#64748B'
                                }
                            },
                            x: {
                                grid: {
                                    display: false,
                                    drawBorder: false
                                },
                                ticks: {
                                    font: { size: 12 },
                                    color: '#64748B'
                                }
                            }
                        }
                    }
                });
            }

            // Booking Status Chart
            const bookingCtx = document.getElementById('bookingChart');
            if (bookingCtx) {
                const totalBookings = <?php echo $total_bookings; ?>;
                const chartData = [<?php echo "$pending_bookings, $approved_bookings, $completed_bookings, $cancelled_bookings"; ?>];
                
                new Chart(bookingCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Completed', 'Cancelled'],
                        datasets: [{
                            data: chartData,
                            backgroundColor: ['#F59E0B', '#10B981', '#007FFF', '#EF4444'],
                            borderColor: '#FFFFFF',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { size: 14, weight: 600 },
                                    padding: 20,
                                    usePointStyle: true
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>