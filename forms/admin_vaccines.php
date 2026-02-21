<?php
/**
 * Admin Vaccines Management - Enhanced with Charts & Analytics
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$feedback = "";

// Update vaccine price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vaccine_id'], $_POST['price'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    $vid = (int)$_POST['vaccine_id'];
    $price = (float)$_POST['price'];
    $stmt = $conn->prepare("UPDATE vaccines SET price = ? WHERE id = ?");
    $stmt->bind_param("di", $price, $vid);
    if ($stmt->execute()) {
        $feedback = "✓ Price updated successfully!";
    }
    $stmt->close();
}

// Fetch vaccine statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_vaccines,
        SUM(quantity) as total_quantity,
        AVG(price) as avg_price,
        MAX(price) as max_price,
        MIN(price) as min_price,
        SUM(quantity * price) as total_inventory_value,
        COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired_count,
        COUNT(CASE WHEN quantity < 10 THEN 1 END) as low_stock_count
    FROM vaccines
";

$stats = $conn->query($stats_query)->fetch_assoc();

// Fetch vaccine distribution by hospital
$hospital_stats_query = "
    SELECT 
        h.name as hospital_name,
        COUNT(v.id) as vaccine_count,
        SUM(v.quantity) as total_quantity,
        AVG(v.price) as avg_price,
        SUM(v.quantity * v.price) as inventory_value
    FROM hospitals h
    LEFT JOIN vaccines v ON h.id = v.hospital_id
    GROUP BY h.id, h.name
    ORDER BY inventory_value DESC
";

$hospital_stats = $conn->query($hospital_stats_query);

// Fetch vaccine distribution by type
$type_stats_query = "
    SELECT 
        type,
        COUNT(*) as vaccine_count,
        SUM(quantity) as total_quantity,
        AVG(price) as avg_price,
        SUM(quantity * price) as total_value
    FROM vaccines
    GROUP BY type
    ORDER BY total_value DESC
";

$type_stats = $conn->query($type_stats_query);

// Fetch all vaccines with search/filter
$search = trim($_GET['search'] ?? '');
$filter_hospital = $_GET['hospital'] ?? 'all';
$sort = $_GET['sort'] ?? 'recent';

$query = "
    SELECT v.*, h.name as hospital_name, h.id as hospital_id
    FROM vaccines v
    JOIN hospitals h ON v.hospital_id = h.id
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (v.name LIKE ? OR v.type LIKE ? OR v.batch_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($filter_hospital !== 'all') {
    $query .= " AND v.hospital_id = ?";
    $hospital_id = (int)$filter_hospital;
    $params[] = $hospital_id;
    $types .= "i";
}

// Apply sorting
if ($sort === 'price_high') {
    $query .= " ORDER BY v.price DESC";
} elseif ($sort === 'price_low') {
    $query .= " ORDER BY v.price ASC";
} elseif ($sort === 'qty_low') {
    $query .= " ORDER BY v.quantity ASC";
} elseif ($sort === 'expiry_soon') {
    $query .= " ORDER BY v.expiry_date ASC";
} else {
    $query .= " ORDER BY v.id DESC";
}

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$vaccines = $stmt->get_result();
$stmt->close();

// Get all hospitals for filter dropdown
$hospitals_for_filter = $conn->query("SELECT id, name FROM hospitals ORDER BY name");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Vaccine Management & Analytics</title>
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

        .feedback {
            background: #d1fae5;
            color: #065f46;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #10b981;
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
        .stat-card.warning { border-color: #f59e0b; }
        .stat-card.danger { border-color: #ef4444; }

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

        .btn-small {
            background: #667eea;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-small:hover {
            background: #764ba2;
            transform: translateY(-1px);
        }

        .input-small {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
            width: 90px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-low-stock {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-ok {
            background: #d1fae5;
            color: #065f46;
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
                <h1><i class="fas fa-syringe"></i> Vaccine Management</h1>
                <p style="color: #6b7280; margin-top: 8px;">Inventory, Pricing & Analytics</p>
            </div>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </header>

        <?php if ($feedback): ?>
        <div class="feedback"><?php echo $feedback; ?></div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-pills"></i> Total Vaccines</div>
                <div class="stat-value"><?php echo $stats['total_vaccines'] ?? 0; ?></div>
                <div class="stat-desc">Active vaccine types</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label"><i class="fas fa-box"></i> Total Inventory</div>
                <div class="stat-value"><?php echo number_format($stats['total_quantity'] ?? 0, 0); ?></div>
                <div class="stat-desc">Total units in stock</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-coins"></i> Inventory Value</div>
                <div class="stat-value">PKR <?php echo number_format($stats['total_inventory_value'] ?? 0, 0); ?></div>
                <div class="stat-desc">Total stock value</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Low Stock</div>
                <div class="stat-value"><?php echo $stats['low_stock_count'] ?? 0; ?></div>
                <div class="stat-desc">Items below 10 units</div>
            </div>

            <div class="stat-card danger">
                <div class="stat-label"><i class="fas fa-calendar-times"></i> Expired</div>
                <div class="stat-value"><?php echo $stats['expired_count'] ?? 0; ?></div>
                <div class="stat-desc">Expired vaccines</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label"><i class="fas fa-chart-line"></i> Avg Price</div>
                <div class="stat-value">PKR <?php echo number_format($stats['avg_price'] ?? 0, 0); ?></div>
                <div class="stat-desc">Average vaccine cost</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="section">
            <h2><i class="fas fa-chart-bar"></i> Analytics & Distribution</h2>
            <div class="charts-grid">
                <!-- Vaccine Distribution by Type -->
                <div class="chart-container">
                    <canvas id="typeChart"></canvas>
                </div>

                <!-- Hospital Inventory Value -->
                <div class="chart-container">
                    <canvas id="hospitalChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Hospital Statistics -->
        <div class="section">
            <h2><i class="fas fa-hospital"></i> Inventory by Hospital</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Hospital Name</th>
                            <th>Vaccine Count</th>
                            <th>Total Quantity</th>
                            <th>Avg Price</th>
                            <th>Inventory Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($hospital_stats->num_rows > 0) {
                            while ($h = $hospital_stats->fetch_assoc()) {
                                echo "
                                <tr>
                                    <td><strong>{$h['hospital_name']}</strong></td>
                                    <td>{$h['vaccine_count']}</td>
                                    <td>" . number_format($h['total_quantity'] ?? 0, 0) . "</td>
                                    <td>PKR " . number_format($h['avg_price'] ?? 0, 0) . "</td>
                                    <td><strong>PKR " . number_format($h['inventory_value'] ?? 0, 0) . "</strong></td>
                                </tr>
                                ";
                            }
                        } else {
                            echo "<tr><td colspan='5' style='text-align:center; color:#9ca3af;'>No hospital data</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Vaccines Table -->
        <div class="section">
            <h2><i class="fas fa-list"></i> All Vaccines</h2>

            <!-- Filters -->
            <div class="filter-section">
                <input type="text" placeholder="Search vaccine name, type, or batch..." id="searchInput" value="<?php echo htmlspecialchars($search); ?>">
                <select id="hospitalFilter">
                    <option value="all">All Hospitals</option>
                    <?php
                    $hospitals_for_filter->data_seek(0);
                    while ($h = $hospitals_for_filter->fetch_assoc()) {
                        $selected = ($filter_hospital == $h['id']) ? 'selected' : '';
                        echo "<option value='{$h['id']}' $selected>{$h['name']}</option>";
                    }
                    ?>
                </select>
                <select id="sortFilter">
                    <option value="recent">Recent</option>
                    <option value="price_high">Price High to Low</option>
                    <option value="price_low">Price Low to High</option>
                    <option value="qty_low">Low Stock First</option>
                    <option value="expiry_soon">Expiry Soon</option>
                </select>
            </div>

            <!-- Vaccines Table -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Vaccine Name</th>
                            <th>Type</th>
                            <th>Hospital</th>
                            <th>Batch #</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($vaccines->num_rows > 0) {
                            while ($v = $vaccines->fetch_assoc()) {
                                $expired = $v['expiry_date'] < date('Y-m-d');
                                $low_stock = $v['quantity'] < 10;

                                if ($expired) {
                                    $status = '<span class="status-badge badge-expired">Expired</span>';
                                } elseif ($low_stock) {
                                    $status = '<span class="status-badge badge-low-stock">Low Stock</span>';
                                } else {
                                    $status = '<span class="status-badge badge-ok">OK</span>';
                                }

                                echo "
                                <tr>
                                    <td>{$v['id']}</td>
                                    <td><strong>{$v['name']}</strong></td>
                                    <td>{$v['type']}</td>
                                    <td>{$v['hospital_name']}</td>
                                    <td><code style='background:#f0f0f0; padding:3px 6px;'>{$v['batch_number']}</code></td>
                                    <td><strong>" . number_format($v['quantity'], 0) . "</strong></td>
                                    <td><strong>PKR " . number_format($v['price'], 0) . "</strong></td>
                                    <td>" . date('d M Y', strtotime($v['expiry_date'])) . "</td>
                                    <td>$status</td>
                                    <td>
                                        <form method='post' style='display:inline-block'>
                                            " . csrf_field() . "
                                            <input type='hidden' name='vaccine_id' value='{$v['id']}'>
                                            <input class='input-small' type='number' name='price' step='0.01' value='{$v['price']}' required>
                                            <button class='btn-small' type='submit'>Update</button>
                                        </form>
                                    </td>
                                </tr>
                                ";
                            }
                        } else {
                            echo "<tr><td colspan='10' style='text-align:center; color:#9ca3af;'>No vaccines found</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal - Vaccine Management System</p>
    </footer>

    <script>
        // Chart Data - Vaccine Distribution by Type
        const typeData = <?php
            $type_stats_data = [];
            $type_names = [];
            $type_values = [];
            $conn->query($type_stats_query);
            $result = $conn->query($type_stats_query);
            while ($row = $result->fetch_assoc()) {
                $type_names[] = $row['type'];
                $type_values[] = (int)$row['vaccine_count'];
            }
            echo json_encode(['labels' => $type_names, 'data' => $type_values]);
        ?>;

        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: typeData.labels,
                datasets: [{
                    data: typeData.data,
                    backgroundColor: [
                        '#667eea', '#764ba2', '#f093fb', '#4facfe',
                        '#43e97b', '#fa709a', '#feca57', '#48dbfb'
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

        // Chart Data - Hospital Inventory Value
        const hospitalData = <?php
            $hospital_names = [];
            $hospital_values = [];
            $hospital_stats = $conn->query($hospital_revenue_query ?? "SELECT h.name, SUM(v.quantity * v.price) as inventory_value FROM hospitals h LEFT JOIN vaccines v ON h.id = v.hospital_id GROUP BY h.id ORDER BY inventory_value DESC");
            while ($row = $hospital_stats->fetch_assoc()) {
                $hospital_names[] = $row['name'] ?? $row['hospital_name'];
                $hospital_values[] = (int)($row['inventory_value'] ?? 0);
            }
            echo json_encode(['labels' => $hospital_names, 'data' => $hospital_values]);
        ?>;

        const hospitalCtx = document.getElementById('hospitalChart').getContext('2d');
        new Chart(hospitalCtx, {
            type: 'bar',
            data: {
                labels: hospitalData.labels,
                datasets: [{
                    label: 'Inventory Value (PKR)',
                    data: hospitalData.data,
                    backgroundColor: '#667eea',
                    borderColor: '#667eea',
                    borderWidth: 1,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });

        // Filter functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const params = new URLSearchParams();
            params.set('search', this.value);
            params.set('hospital', document.getElementById('hospitalFilter').value);
            params.set('sort', document.getElementById('sortFilter').value);
            window.location = '?' + params.toString();
        });

        document.getElementById('hospitalFilter').addEventListener('change', function() {
            const params = new URLSearchParams();
            params.set('search', document.getElementById('searchInput').value);
            params.set('hospital', this.value);
            params.set('sort', document.getElementById('sortFilter').value);
            window.location = '?' + params.toString();
        });

        document.getElementById('sortFilter').addEventListener('change', function() {
            const params = new URLSearchParams();
            params.set('search', document.getElementById('searchInput').value);
            params.set('hospital', document.getElementById('hospitalFilter').value);
            params.set('sort', this.value);
            window.location = '?' + params.toString();
        });
    </script>
</body>
</html>
