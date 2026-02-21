<?php
/**
 * Admin Parents Management - Premium Dashboard with Analytics
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'recent';

// Fetch parent statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT p.id) as total_parents,
        COUNT(DISTINCT CASE WHEN p.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN p.id END) as new_parents_30d,
        COUNT(DISTINCT c.id) as total_children,
        COUNT(DISTINCT b.id) as total_bookings,
        COUNT(DISTINCT CASE WHEN pay.status = 'completed' THEN pay.id END) as completed_payments,
        COALESCE(SUM(CASE WHEN pay.status = 'completed' THEN pay.amount ELSE 0 END), 0) as total_spending
    FROM parents p
    LEFT JOIN children c ON p.id = c.parent_id
    LEFT JOIN bookings b ON p.id = b.parent_id
    LEFT JOIN payments pay ON b.id = pay.booking_id
";

$stats = $conn->query($stats_query)->fetch_assoc();

// Get parent details with engagement metrics
$parent_query = "
    SELECT 
        p.id,
        p.name,
        p.email,
        p.phone,
        p.created_at,
        COUNT(DISTINCT c.id) as children_count,
        COUNT(DISTINCT b.id) as booking_count,
        COUNT(DISTINCT CASE WHEN pay.status = 'completed' THEN pay.id END) as payment_count,
        COALESCE(SUM(CASE WHEN pay.status = 'completed' THEN pay.amount ELSE 0 END), 0) as total_spent,
        COUNT(DISTINCT hr.id) as reviews_given,
        COALESCE(AVG(hr.stars), 0) as avg_rating_given
    FROM parents p
    LEFT JOIN children c ON p.id = c.parent_id
    LEFT JOIN bookings b ON p.id = b.parent_id
    LEFT JOIN payments pay ON b.id = pay.booking_id
    LEFT JOIN hospital_ratings hr ON p.id = hr.parent_id
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $parent_query .= " AND (p.name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$parent_query .= " GROUP BY p.id, p.name, p.email, p.phone, p.created_at";

// Apply sorting
if ($sort === 'spending_high') {
    $parent_query .= " ORDER BY total_spent DESC";
} elseif ($sort === 'spending_low') {
    $parent_query .= " ORDER BY total_spent ASC";
} elseif ($sort === 'bookings_high') {
    $parent_query .= " ORDER BY booking_count DESC";
} elseif ($sort === 'children_high') {
    $parent_query .= " ORDER BY children_count DESC";
} else {
    $parent_query .= " ORDER BY p.created_at DESC";
}

$stmt = $conn->prepare($parent_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$parents = $stmt->get_result();
$stmt->close();

// Top spending parents for charts
$top_parents_query = "
    SELECT 
        p.name,
        COALESCE(SUM(CASE WHEN pay.status = 'completed' THEN pay.amount ELSE 0 END), 0) as spending,
        COUNT(DISTINCT b.id) as bookings
    FROM parents p
    LEFT JOIN bookings b ON p.id = b.parent_id
    LEFT JOIN payments pay ON b.id = pay.booking_id
    GROUP BY p.id, p.name
    HAVING spending > 0
    ORDER BY spending DESC
    LIMIT 10
";

$top_parents = $conn->query($top_parents_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Parents Management & Analytics</title>
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
            max-width: 1600px;
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
            background-clip: text;
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

        .stat-card.primary { border-color: #667eea; }
        .stat-card.success { border-color: #10b981; }
        .stat-card.info { border-color: #3b82f6; }

        .stat-label {
            font-size: 13px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 8px;
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
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            background: #f9fafb;
            border-radius: 10px;
            padding: 20px;
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

        footer {
            text-align: center;
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            margin-top: 50px;
        }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1><i class="fas fa-users"></i> Parents Management</h1>
                <p style="color: #6b7280; margin-top: 8px;">Analytics, Engagement & Spending Metrics</p>
            </div>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </header>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-users"></i> Total Parents</div>
                <div class="stat-value"><?php echo $stats['total_parents'] ?? 0; ?></div>
                <div class="stat-desc">Active parent accounts</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label"><i class="fas fa-coins"></i> Total Spending</div>
                <div class="stat-value">PKR <?php echo number_format($stats['total_spending'] ?? 0, 0); ?></div>
                <div class="stat-desc">From all completed payments</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label"><i class="fas fa-child"></i> Total Children</div>
                <div class="stat-value"><?php echo $stats['total_children'] ?? 0; ?></div>
                <div class="stat-desc">Registered children</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-calendar"></i> Total Bookings</div>
                <div class="stat-value"><?php echo $stats['total_bookings'] ?? 0; ?></div>
                <div class="stat-desc">Vaccination appointments</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label"><i class="fas fa-credit-card"></i> Payments Completed</div>
                <div class="stat-value"><?php echo $stats['completed_payments'] ?? 0; ?></div>
                <div class="stat-desc">Successful transactions</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label"><i class="fas fa-plus-circle"></i> New (30 days)</div>
                <div class="stat-value"><?php echo $stats['new_parents_30d'] ?? 0; ?></div>
                <div class="stat-desc">Recently registered</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="section">
            <h2><i class="fas fa-chart-bar"></i> Engagement & Spending Analytics</h2>
            <div class="charts-grid">
                <!-- Top Parents by Spending -->
                <div class="chart-container">
                    <canvas id="spendingChart"></canvas>
                </div>

                <!-- Top Parents by Bookings -->
                <div class="chart-container">
                    <canvas id="engagementChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Parents Table -->
        <div class="section">
            <h2><i class="fas fa-list"></i> All Parents</h2>

            <!-- Filters -->
            <div class="filter-section">
                <input type="text" placeholder="Search parent name, email, or phone..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                <select id="sortFilter">
                    <option value="recent">Recently Registered</option>
                    <option value="spending_high">Highest Spending</option>
                    <option value="spending_low">Lowest Spending</option>
                    <option value="bookings_high">Most Active</option>
                    <option value="children_high">Most Children</option>
                </select>
            </div>

            <!-- Parents Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Parent Name</th>
                            <th>Contact</th>
                            <th>Children</th>
                            <th>Bookings</th>
                            <th>Payments</th>
                            <th>Total Spending</th>
                            <th>Reviews Given</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($parents->num_rows > 0) {
                            while ($p = $parents->fetch_assoc()) {
                                echo "
                                <tr>
                                    <td><strong>{$p['name']}</strong></td>
                                    <td>{$p['email']}<br><small style='color:#9ca3af;'>{$p['phone']}</small></td>
                                    <td>{$p['children_count']}</td>
                                    <td>{$p['booking_count']}</td>
                                    <td>{$p['payment_count']}</td>
                                    <td><strong>PKR " . number_format($p['total_spent'], 0) . "</strong></td>
                                    <td>{$p['reviews_given']}</td>
                                    <td>" . date('d M Y', strtotime($p['created_at'])) . "</td>
                                </tr>
                                ";
                            }
                        } else {
                            echo "<tr><td colspan='8' style='text-align:center; color:#9ca3af;'>No parents found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal - Parent Management System</p>
    </footer>

    <script>
        // Chart Data - Top Parents by Spending
        const spendingData = <?php
            $p_names = [];
            $p_spending = [];
            $top_parents->data_seek(0);
            while ($row = $top_parents->fetch_assoc()) {
                $p_names[] = $row['name'];
                $p_spending[] = (int)$row['spending'];
            }
            echo json_encode(['labels' => $p_names, 'data' => $p_spending]);
        ?>;

        const spendingCtx = document.getElementById('spendingChart').getContext('2d');
        new Chart(spendingCtx, {
            type: 'bar',
            data: {
                labels: spendingData.labels,
                datasets: [{
                    label: 'Total Spending (PKR)',
                    data: spendingData.data,
                    backgroundColor: '#667eea',
                    borderColor: '#667eea',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });

        // Chart Data - Top Parents by Bookings
        const engagementData = <?php
            $b_names = [];
            $b_count = [];
            $top_parents->data_seek(0);
            while ($row = $top_parents->fetch_assoc()) {
                $b_names[] = $row['name'];
                $b_count[] = (int)$row['bookings'];
            }
            echo json_encode(['labels' => $b_names, 'data' => $b_count]);
        ?>;

        const engagementCtx = document.getElementById('engagementChart').getContext('2d');
        new Chart(engagementCtx, {
            type: 'doughnut',
            data: {
                labels: engagementData.labels,
                datasets: [{
                    data: engagementData.data,
                    backgroundColor: [
                        '#667eea', '#764ba2', '#f093fb', '#4facfe',
                        '#43e97b', '#fa709a', '#feca57', '#48dbfb',
                        '#ff6b9d', '#c44569'
                    ],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        // Filter functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const params = new URLSearchParams();
            params.set('search', this.value);
            params.set('sort', document.getElementById('sortFilter').value);
            window.location = '?' + params.toString();
        });

        document.getElementById('sortFilter').addEventListener('change', function() {
            const params = new URLSearchParams();
            params.set('search', document.getElementById('searchInput').value);
            params.set('sort', this.value);
            window.location = '?' + params.toString();
        });
    </script>
</body>
</html>
