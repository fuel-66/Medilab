<?php
/**
 * Admin Hospitals Management - Premium Dashboard with Analytics
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

// Fetch hospital statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_hospitals,
        COUNT(CASE WHEN h.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as new_hospitals_30d,
        COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) as total_revenue,
        COUNT(DISTINCT b.id) as total_bookings,
        COUNT(DISTINCT b.parent_id) as total_parents_served
    FROM hospitals h
    LEFT JOIN vaccines v ON h.id = v.hospital_id
    LEFT JOIN bookings b ON h.id = b.hospital_id
    LEFT JOIN payments p ON b.id = p.booking_id
";

$stats = $conn->query($stats_query)->fetch_assoc();

// Get hospital performance data
$hospital_query = "
    SELECT 
        h.id,
        h.name,
        h.email,
        h.phone,
        h.address,
        h.created_at,
        COUNT(DISTINCT v.id) as vaccine_count,
        SUM(v.quantity) as total_vaccines,
        COUNT(DISTINCT b.id) as booking_count,
        COUNT(DISTINCT b.parent_id) as parents_served,
        COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) as total_revenue,
        COALESCE(AVG(hr.stars), 0) as avg_rating,
        COUNT(DISTINCT hr.id) as rating_count
    FROM hospitals h
    LEFT JOIN vaccines v ON h.id = v.hospital_id
    LEFT JOIN bookings b ON h.id = b.hospital_id
    LEFT JOIN payments p ON b.id = p.booking_id
    LEFT JOIN hospital_ratings hr ON h.id = hr.hospital_id
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $hospital_query .= " AND (h.name LIKE ? OR h.email LIKE ? OR h.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$hospital_query .= " GROUP BY h.id, h.name, h.email, h.phone, h.address, h.created_at";

// Apply sorting
if ($sort === 'revenue_high') {
    $hospital_query .= " ORDER BY total_revenue DESC";
} elseif ($sort === 'revenue_low') {
    $hospital_query .= " ORDER BY total_revenue ASC";
} elseif ($sort === 'bookings_high') {
    $hospital_query .= " ORDER BY booking_count DESC";
} elseif ($sort === 'rating_high') {
    $hospital_query .= " ORDER BY avg_rating DESC";
} else {
    $hospital_query .= " ORDER BY h.created_at DESC";
}

$stmt = $conn->prepare($hospital_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$hospitals = $stmt->get_result();
$stmt->close();

// Top performing hospitals for charts
$top_hospitals_query = "
    SELECT 
        h.name,
        COALESCE(SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END), 0) as revenue,
        COUNT(DISTINCT b.id) as bookings
    FROM hospitals h
    LEFT JOIN bookings b ON h.id = b.hospital_id
    LEFT JOIN payments p ON b.id = p.booking_id
    GROUP BY h.id, h.name
    ORDER BY revenue DESC
    LIMIT 10
";

$top_hospitals = $conn->query($top_hospitals_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Hospitals Management & Analytics</title>
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

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-verified {
            background: #d1fae5;
            color: #065f46;
        }

        .stars {
            color: #fbbf24;
            font-size: 14px;
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
                <h1><i class="fas fa-hospital"></i> Hospital Management</h1>
                <p style="color: #6b7280; margin-top: 8px;">Analytics, Performance & Revenue Tracking</p>
            </div>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </header>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-hospital"></i> Total Hospitals</div>
                <div class="stat-value"><?php echo $stats['total_hospitals'] ?? 0; ?></div>
                <div class="stat-desc">Active hospital partners</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label"><i class="fas fa-coins"></i> Total Revenue</div>
                <div class="stat-value">PKR <?php echo number_format($stats['total_revenue'] ?? 0, 0); ?></div>
                <div class="stat-desc">From all hospitals combined</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label"><i class="fas fa-calendar"></i> Total Bookings</div>
                <div class="stat-value"><?php echo $stats['total_bookings'] ?? 0; ?></div>
                <div class="stat-desc">Appointments across all hospitals</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-users"></i> Parents Served</div>
                <div class="stat-value"><?php echo $stats['total_parents_served'] ?? 0; ?></div>
                <div class="stat-desc">Unique parent customers</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label"><i class="fas fa-plus-circle"></i> New (30 days)</div>
                <div class="stat-value"><?php echo $stats['new_hospitals_30d'] ?? 0; ?></div>
                <div class="stat-desc">Recently added hospitals</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="section">
            <h2><i class="fas fa-chart-bar"></i> Performance Analytics</h2>
            <div class="charts-grid">
                <!-- Top Hospitals by Revenue -->
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>

                <!-- Top Hospitals by Bookings -->
                <div class="chart-container">
                    <canvas id="bookingsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Hospitals Table -->
        <div class="section">
            <h2><i class="fas fa-list"></i> All Hospitals</h2>

            <!-- Filters -->
            <div class="filter-section">
                <input type="text" placeholder="Search hospital name, email, or phone..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                <select id="sortFilter">
                    <option value="recent">Recently Added</option>
                    <option value="revenue_high">Revenue High to Low</option>
                    <option value="revenue_low">Revenue Low to High</option>
                    <option value="bookings_high">Most Bookings</option>
                    <option value="rating_high">Highest Rating</option>
                </select>
            </div>

            <!-- Hospitals Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Hospital Name</th>
                            <th>Contact</th>
                            <th>Vaccines</th>
                            <th>Bookings</th>
                            <th>Parents Served</th>
                            <th>Rating</th>
                            <th>Total Revenue</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($hospitals->num_rows > 0) {
                            while ($h = $hospitals->fetch_assoc()) {
                                $stars = '';
                                for ($i = 0; $i < floor($h['avg_rating']); $i++) {
                                    $stars .= '<i class="fas fa-star stars"></i>';
                                }
                                if ($h['avg_rating'] - floor($h['avg_rating']) >= 0.5) {
                                    $stars .= '<i class="fas fa-star-half-alt stars"></i>';
                                }

                                echo "
                                <tr>
                                    <td><strong>{$h['name']}</strong></td>
                                    <td>{$h['email']}<br><small style='color:#9ca3af;'>{$h['phone']}</small></td>
                                    <td>{$h['vaccine_count']}</td>
                                    <td>{$h['booking_count']}</td>
                                    <td>{$h['parents_served']}</td>
                                    <td>$stars <small>({$h['rating_count']})</small></td>
                                    <td><strong>PKR " . number_format($h['total_revenue'], 0) . "</strong></td>
                                    <td>" . date('d M Y', strtotime($h['created_at'])) . "</td>
                                </tr>
                                ";
                            }
                        } else {
                            echo "<tr><td colspan='8' style='text-align:center; color:#9ca3af;'>No hospitals found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal - Hospital Management System</p>
    </footer>

    <script>
        // Chart Data - Top Hospitals by Revenue
        const revenueData = <?php
            $h_names = [];
            $h_revenue = [];
            $top_hospitals->data_seek(0);
            while ($row = $top_hospitals->fetch_assoc()) {
                $h_names[] = $row['name'];
                $h_revenue[] = (int)$row['revenue'];
            }
            echo json_encode(['labels' => $h_names, 'data' => $h_revenue]);
        ?>;

        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: revenueData.labels,
                datasets: [{
                    label: 'Total Revenue (PKR)',
                    data: revenueData.data,
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

        // Chart Data - Top Hospitals by Bookings
        const bookingsData = <?php
            $b_names = [];
            $b_count = [];
            $top_hospitals->data_seek(0);
            while ($row = $top_hospitals->fetch_assoc()) {
                $b_names[] = $row['name'];
                $b_count[] = (int)$row['bookings'];
            }
            echo json_encode(['labels' => $b_names, 'data' => $b_count]);
        ?>;

        const bookingsCtx = document.getElementById('bookingsChart').getContext('2d');
        new Chart(bookingsCtx, {
            type: 'doughnut',
            data: {
                labels: bookingsData.labels,
                datasets: [{
                    data: bookingsData.data,
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
