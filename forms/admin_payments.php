<?php
/**
 * Admin Payments Dashboard - Enhanced
 * Shows payment details, revenue, profits, and hospital payouts (50/50 split)
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];

// Fetch admin info
$stmt = $conn->prepare("SELECT id, name, email FROM admins WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get filter parameters
$payment_status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'recent';

// Build query for payments
$query = "
    SELECT 
        p.id as payment_id,
        p.amount,
        p.status,
        p.payment_gateway,
        p.created_at,
        p.paid_at,
        par.id as parent_id,
        par.name as parent_name,
        par.email as parent_email,
        par.phone as parent_phone,
        c.name as child_name,
        h.id as hospital_id,
        h.name as hospital_name,
        COALESCE(GROUP_CONCAT(DISTINCT v.name SEPARATOR ', '), 'N/A') as vaccines
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.id
    LEFT JOIN parents par ON p.parent_id = par.id
    LEFT JOIN children c ON b.child_id = c.id
    LEFT JOIN hospitals h ON b.hospital_id = h.id
    LEFT JOIN booking_vaccinations bv ON b.id = bv.booking_id
    LEFT JOIN vaccines v ON bv.vaccine_id = v.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($payment_status_filter !== 'all') {
    $query .= " AND p.status = ?";
    $params[] = $payment_status_filter;
    $types .= "s";
}

if (!empty($search)) {
    $query .= " AND (par.name LIKE ? OR par.email LIKE ? OR c.name LIKE ? OR h.name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$query .= " GROUP BY p.id";

// Add sorting
if ($sort === 'recent') {
    $query .= " ORDER BY p.created_at DESC";
} elseif ($sort === 'oldest') {
    $query .= " ORDER BY p.created_at ASC";
} elseif ($sort === 'amount_high') {
    $query .= " ORDER BY p.amount DESC";
} elseif ($sort === 'amount_low') {
    $query .= " ORDER BY p.amount ASC";
}

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Query prepare failed: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
if (!$stmt->execute()) {
    die("Query execute failed: " . $stmt->error);
}
$payments = $stmt->get_result();
$stmt->close();

// Get comprehensive statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_transactions,
        COUNT(DISTINCT p.parent_id) as parents_paid,
        COUNT(DISTINCT b.hospital_id) as hospitals_served,
        COALESCE(SUM(p.amount), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END), 0) as total_pending,
        COALESCE(SUM(CASE WHEN p.status = 'failed' THEN p.amount ELSE 0 END), 0) as total_failed,
        SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN p.status = 'failed' THEN 1 ELSE 0 END) as failed_count
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.id
";

$stats_result = $conn->query($stats_query);
if (!$stats_result) {
    die("Stats query failed: " . $conn->error);
}
$stats = $stats_result->fetch_assoc();

$total_revenue = (float)($stats['total_revenue'] ?? 0);
$hospital_payout = $total_revenue * 0.5;  // 50% to hospitals
$admin_profit = $total_revenue * 0.5;     // 50% profit for company

// Make sure stats have defaults
if (!$stats) {
    $stats = [
        'total_transactions' => 0,
        'parents_paid' => 0,
        'hospitals_served' => 0,
        'total_revenue' => 0,
        'total_pending' => 0,
        'completed_count' => 0,
        'pending_count' => 0,
        'failed_count' => 0
    ];
}

// Get revenue by parent
$parent_revenue_query = "
    SELECT 
        par.id,
        par.name,
        par.email,
        COUNT(p.id) as transaction_count,
        COALESCE(SUM(p.amount), 0) as total_paid
    FROM payments p
    JOIN parents par ON p.parent_id = par.id
    WHERE p.status = 'completed'
    GROUP BY par.id, par.name, par.email
    ORDER BY total_paid DESC
    LIMIT 10
";

$parent_revenue = $conn->query($parent_revenue_query);
if (!$parent_revenue) {
    die("Parent revenue query failed: " . $conn->error);
}

// Get revenue by hospital
$hospital_revenue_query = "
    SELECT 
        h.id,
        h.name,
        COUNT(p.id) as transaction_count,
        COALESCE(SUM(p.amount), 0) as total_earned,
        COALESCE(SUM(p.amount) * 0.5, 0) as payout_50_percent
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN hospitals h ON b.hospital_id = h.id
    WHERE p.status = 'completed'
    GROUP BY h.id, h.name
    ORDER BY total_earned DESC
    LIMIT 10
";

$hospital_revenue = $conn->query($hospital_revenue_query);
if (!$hospital_revenue) {
    die("Hospital revenue query failed: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Payments & Revenue Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Poppins', sans-serif;
            color: #1f2937;
            min-height: 100vh;
            padding: 30px 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        header h1 {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-left: 5px solid;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stat-card.revenue {
            border-color: #10b981;
        }

        .stat-card.profit {
            border-color: #fbbf24;
        }

        .stat-card.hospital {
            border-color: #3b82f6;
        }

        .stat-card.parents {
            border-color: #8b5cf6;
        }

        .stat-label {
            font-size: 13px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .stat-desc {
            font-size: 12px;
            color: #9ca3af;
        }

        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .section h2 {
            font-size: 22px;
            margin-bottom: 25px;
            color: #1f2937;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section h2 i {
            color: #667eea;
            font-size: 24px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f9fafb;
        }

        th {
            padding: 15px;
            text-align: left;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        tr:hover {
            background: #f9fafb;
        }

        .parent-name {
            font-weight: 700;
            color: #1f2937;
        }

        .hospital-name {
            font-weight: 700;
            color: #1f2937;
        }

        .amount {
            font-weight: 700;
            color: #10b981;
            font-size: 14px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .filter-section {
            background: #f9fafb;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-section input,
        .filter-section select {
            padding: 10px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
        }

        .filter-section input:focus,
        .filter-section select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .no-data {
            text-align: center;
            color: #9ca3af;
            padding: 40px;
            font-style: italic;
        }

        footer {
            text-align: center;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            margin-top: 50px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1><i class="fas fa-chart-line"></i> Revenue & Payments Dashboard</h1>
                <p style="color: #6b7280; margin-top: 8px;">Admin Control Panel - Financial Overview</p>
            </div>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </header>

        <!-- Financial Statistics -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-label"><i class="fas fa-coins"></i> Total Revenue</div>
                <div class="stat-value">PKR <?php echo number_format($total_revenue, 0); ?></div>
                <div class="stat-desc">From all completed payments</div>
            </div>

            <div class="stat-card profit">
                <div class="stat-label"><i class="fas fa-piggy-bank"></i> Company Profit (50%)</div>
                <div class="stat-value">PKR <?php echo number_format($admin_profit, 0); ?></div>
                <div class="stat-desc">Your earnings after payout</div>
            </div>

            <div class="stat-card hospital">
                <div class="stat-label"><i class="fas fa-building"></i> Hospital Payout (50%)</div>
                <div class="stat-value">PKR <?php echo number_format($hospital_payout, 0); ?></div>
                <div class="stat-desc">Due to hospitals</div>
            </div>

            <div class="stat-card parents">
                <div class="stat-label"><i class="fas fa-check-circle"></i> Completed Payments</div>
                <div class="stat-value"><?php echo (int)($stats['completed_count'] ?? 0); ?></div>
                <div class="stat-desc">Successfully processed payments</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="content-grid">
            <!-- Revenue Trend -->
            <div class="section">
                <h2><i class="fas fa-chart-line"></i> Revenue Trend (Last 12 Months)</h2>
                <div class="chart-container" style="position: relative; height: 300px;">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>

            <!-- Payment Status Distribution -->
            <div class="section">
                <h2><i class="fas fa-pie-chart"></i> Payment Status Distribution</h2>
                <div class="chart-container" style="position: relative; height: 300px;">
                    <canvas id="paymentStatusChart"></canvas>
                </div>
            </div>
        </div>
            <!-- Top Paying Parents -->
            <div class="section">
                <h2><i class="fas fa-user-tie"></i> Top Paying Parents</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Parent Name</th>
                                <th>Email</th>
                                <th>Transactions</th>
                                <th>Total Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($parent_revenue->num_rows > 0) {
                                while ($parent = $parent_revenue->fetch_assoc()) {
                                    echo "
                                    <tr>
                                        <td class='parent-name'>{$parent['name']}</td>
                                        <td>{$parent['email']}</td>
                                        <td>{$parent['transaction_count']}</td>
                                        <td class='amount'>PKR " . number_format($parent['total_paid'], 0) . "</td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='4' class='no-data'>No payments yet</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Hospital Revenue Breakdown -->
            <div class="section">
                <h2><i class="fas fa-hospital"></i> Hospital Revenue & Payouts (50%)</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Hospital Name</th>
                                <th>Transactions</th>
                                <th>Total Earned</th>
                                <th>Hospital Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($hospital_revenue->num_rows > 0) {
                                while ($hospital = $hospital_revenue->fetch_assoc()) {
                                    echo "
                                    <tr>
                                        <td class='hospital-name'>{$hospital['name']}</td>
                                        <td>{$hospital['transaction_count']}</td>
                                        <td class='amount'>PKR " . number_format($hospital['total_earned'], 0) . "</td>
                                        <td class='amount' style='color: #3b82f6;'>PKR " . number_format($hospital['payout_50_percent'], 0) . "</td>
                                    </tr>
                                    ";
                                }
                            } else {
                                echo "<tr><td colspan='4' class='no-data'>No hospital revenue yet</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- All Payments Table -->
        <div class="section">
            <h2><i class="fas fa-receipt"></i> All Payments</h2>

            <!-- Filters -->
            <div class="filter-section">
                <input type="text" placeholder="Search by parent, email, child, or hospital..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                <select id="statusFilter">
                    <option value="all" <?php echo $payment_status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="completed" <?php echo $payment_status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="pending" <?php echo $payment_status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="failed" <?php echo $payment_status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                </select>
                <select id="sortFilter">
                    <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Recent First</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="amount_high" <?php echo $sort === 'amount_high' ? 'selected' : ''; ?>>Amount High to Low</option>
                    <option value="amount_low" <?php echo $sort === 'amount_low' ? 'selected' : ''; ?>>Amount Low to High</option>
                </select>
            </div>

            <!-- Payments Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Parent</th>
                            <th>Child</th>
                            <th>Hospital</th>
                            <th>Vaccines</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Gateway</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($payments->num_rows > 0) {
                            while ($payment = $payments->fetch_assoc()) {
                                $status_class = 'badge-' . $payment['status'];
                                echo "
                                <tr>
                                    <td class='parent-name'>{$payment['parent_name']}<br><small style='color: #9ca3af;'>{$payment['parent_email']}</small></td>
                                    <td>{$payment['child_name']}</td>
                                    <td class='hospital-name'>{$payment['hospital_name']}</td>
                                    <td><small>{$payment['vaccines']}</small></td>
                                    <td class='amount'>PKR " . number_format($payment['amount'], 0) . "</td>
                                    <td><span class='status-badge {$status_class}'>" . strtoupper($payment['status']) . "</span></td>
                                    <td>" . ucfirst($payment['payment_gateway']) . "</td>
                                    <td>" . date('d M Y', strtotime($payment['created_at'])) . "</td>
                                </tr>
                                ";
                            }
                        } else {
                            echo "<tr><td colspan='8' class='no-data'>No payments found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal - Admin Panel</p>
    </footer>

    <script>
        // Revenue Trend Chart - Last 12 Months
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const currentMonth = new Date().getMonth();
        const last12Months = [];
        for (let i = 11; i >= 0; i--) {
            const monthIndex = (currentMonth - i + 12) % 12;
            last12Months.push(months[monthIndex]);
        }

        <?php
        // Fetch monthly revenue data
        $monthly_revenue_query = "
            SELECT 
                DATE_FORMAT(p.created_at, '%Y-%m') as month,
                SUM(p.amount) as revenue,
                COUNT(*) as transaction_count
            FROM payments p
            WHERE p.status = 'completed'
                AND p.created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(p.created_at, '%Y-%m')
            ORDER BY p.created_at ASC
        ";
        
        $monthly_data = [];
        $result = $conn->query($monthly_revenue_query);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $monthly_data[$row['month']] = (int)$row['revenue'];
            }
        }

        // Build revenue array for chart
        $revenue_array = [];
        $today = new DateTime();
        for ($i = 11; $i >= 0; $i--) {
            $date = clone $today;
            $date->modify("-$i months");
            $key = $date->format('Y-m');
            $revenue_array[] = $monthly_data[$key] ?? 0;
        }
        ?>

        const revenueCtx = document.getElementById('revenueTrendChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: last12Months,
                datasets: [{
                    label: 'Revenue (PKR)',
                    data: <?php echo json_encode($revenue_array); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Revenue (PKR)' }
                    }
                }
            }
        });

        // Payment Status Distribution Chart
        const statusCtx = document.getElementById('paymentStatusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Pending', 'Failed'],
                datasets: [{
                    data: [
                        <?php echo (int)($stats['completed_count'] ?? 0); ?>,
                        <?php echo (int)($stats['pending_count'] ?? 0); ?>,
                        <?php echo (int)($stats['failed_count'] ?? 0); ?>
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#f59e0b',
                        '#ef4444'
                    ],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });

        // Update filters
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const params = new URLSearchParams();
            params.set('search', this.value);
            params.set('status', document.getElementById('statusFilter').value);
            params.set('sort', document.getElementById('sortFilter').value);
            window.location = '?' + params.toString();
        });

        document.getElementById('statusFilter').addEventListener('change', function() {
            const params = new URLSearchParams();
            params.set('search', document.getElementById('searchInput').value);
            params.set('status', this.value);
            params.set('sort', document.getElementById('sortFilter').value);
            window.location = '?' + params.toString();
        });

        document.getElementById('sortFilter').addEventListener('change', function() {
            const params = new URLSearchParams();
            params.set('search', document.getElementById('searchInput').value);
            params.set('status', document.getElementById('statusFilter').value);
            params.set('sort', this.value);
            window.location = '?' + params.toString();
        });
    </script>
</body>
</html>
