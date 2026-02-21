<?php
/**
 * Hospital Dashboard - Revenue, Ratings & QR Verification
 * Premium UI with interactive charts and comprehensive management
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['hospital_id'])) {
    header("Location: login.php");
    exit;
}

$hospital_id = (int)$_SESSION['hospital_id'];

// Fetch hospital info
$stmt = $conn->prepare("SELECT id, name, email, phone, address FROM hospitals WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$hospital = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get hospital statistics
$stats_query = "
    SELECT 
        SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as total_revenue,
        COUNT(DISTINCT p.parent_id) as unique_parents,
        COUNT(DISTINCT p.id) as total_payments,
        AVG(r.stars) as avg_rating,
        COUNT(r.id) as total_ratings
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    LEFT JOIN hospital_ratings r ON r.hospital_id = b.hospital_id
    WHERE b.hospital_id = ?
";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get revenue by parent
$parent_breakdown = "
    SELECT 
        par.id,
        par.name,
        par.email,
        COUNT(p.id) as transaction_count,
        SUM(p.amount) as total_amount
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN parents par ON p.parent_id = par.id
    WHERE b.hospital_id = ? AND p.status = 'completed'
    GROUP BY par.id
    ORDER BY total_amount DESC
    LIMIT 10
";

$stmt = $conn->prepare($parent_breakdown);
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$parent_breakdown_result = $stmt->get_result();
$stmt->close();

// Get hospital ratings
$ratings_query = "
    SELECT 
        r.id,
        r.parent_id,
        r.stars,
        r.comment,
        r.created_at,
        par.name as parent_name
    FROM hospital_ratings r
    JOIN parents par ON r.parent_id = par.id
    WHERE r.hospital_id = ?
    ORDER BY r.created_at DESC
";

$stmt = $conn->prepare($ratings_query);
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$ratings = $stmt->get_result();
$stmt->close();

// Get payment breakdown by month
$monthly_revenue = "
    SELECT 
        DATE_FORMAT(p.created_at, '%Y-%m') as month,
        COUNT(p.id) as count,
        SUM(p.amount) as amount
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE b.hospital_id = ? AND p.status = 'completed'
    GROUP BY DATE_FORMAT(p.created_at, '%Y-%m')
    ORDER BY month DESC
    LIMIT 12
";

$stmt = $conn->prepare($monthly_revenue);
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$monthly_result = $stmt->get_result();
$stmt->close();

// Prepare chart data
$monthly_labels = [];
$monthly_amounts = [];
$temp = $monthly_result->fetch_all(MYSQLI_ASSOC);
rsort($temp);
foreach ($temp as $row) {
    $monthly_labels[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthly_amounts[] = (int)$row['amount'];
}

// Handle QR verification
$qr_verification_result = null;
$qr_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_qr'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $qr_error = "❌ Invalid CSRF token";
    } else {
        $qr_data = trim($_POST['qr_data'] ?? '');
        
        if (!empty($qr_data)) {
            try {
                $qr_json = json_decode($qr_data, true);
                
                if ($qr_json && isset($qr_json['booking_id'])) {
                    $booking_id = (int)$qr_json['booking_id'];
                    
                    $stmt = $conn->prepare("
                        SELECT 
                            b.id,
                            b.booking_date,
                            b.booking_time,
                            b.payment_status,
                            par.name as parent_name,
                            par.email,
                            par.phone,
                            c.name as child_name,
                            c.date_of_birth,
                            GROUP_CONCAT(v.name SEPARATOR ', ') as vaccines
                        FROM bookings b
                        JOIN parents par ON b.parent_id = par.id
                        JOIN children c ON b.child_id = c.id
                        JOIN booking_vaccinations bv ON b.id = bv.booking_id
                        JOIN vaccines v ON bv.vaccine_id = v.id
                        WHERE b.id = ? AND b.hospital_id = ?
                        GROUP BY b.id
                    ");
                    
                    $stmt->bind_param("ii", $booking_id, $hospital_id);
                    $stmt->execute();
                    $qr_verification_result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if (!$qr_verification_result) {
                        $qr_error = "❌ QR code not found or invalid for this hospital";
                    }
                } else {
                    $qr_error = "❌ Invalid QR code data format";
                }
            } catch (Exception $e) {
                $qr_error = "❌ Error reading QR code";
            }
        } else {
            $qr_error = "❌ Please paste QR code data";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Dashboard - Revenue & Management</title>
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
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
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

        .stat-card.parents {
            border-color: #3b82f6;
        }

        .stat-card.transactions {
            border-color: #f59e0b;
        }

        .stat-card.rating {
            border-color: #fbbf24;
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
        }

        .rating-display-stat {
            font-size: 14px;
            color: #fbbf24;
            margin-top: 8px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
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

        .chart-container {
            position: relative;
            height: 320px;
            margin-bottom: 20px;
        }

        .parent-item {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #3b82f6;
            transition: all 0.3s;
        }

        .parent-item:hover {
            background: #f3f4f6;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .parent-name {
            font-weight: 700;
            color: #1f2937;
        }

        .parent-email {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }

        .parent-transactions {
            font-size: 13px;
            color: #6b7280;
        }

        .parent-amount {
            text-align: right;
            font-weight: 700;
            color: #10b981;
            font-size: 16px;
        }

        .qr-form {
            background: #f9fafb;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .qr-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            resize: vertical;
            min-height: 80px;
            margin-bottom: 12px;
        }

        .qr-form textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .qr-result {
            background: #f9fafb;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #10b981;
            margin-top: 15px;
        }

        .qr-result.error {
            border-color: #ef4444;
            background: #fee2e2;
        }

        .qr-info {
            display: grid;
            gap: 12px;
        }

        .qr-row {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 15px;
        }

        .qr-label {
            font-weight: 700;
            color: #1f2937;
        }

        .qr-value {
            color: #4b5563;
        }

        .qr-error {
            color: #991b1b;
        }

        .payment-status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .rating-item {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #fbbf24;
        }

        .rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .rating-name {
            font-weight: 700;
            color: #1f2937;
        }

        .rating-date {
            font-size: 12px;
            color: #9ca3af;
        }

        .rating-stars {
            color: #fbbf24;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .rating-comment {
            color: #4b5563;
            font-size: 13px;
            font-style: italic;
        }

        .error-msg {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #ef4444;
        }

        .success-msg {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #10b981;
        }

        .no-data {
            text-align: center;
            color: #9ca3af;
            padding: 30px;
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
                <h1><i class="fas fa-chart-line"></i> Hospital Dashboard</h1>
                <p style="color: #6b7280; margin-top: 8px;"><strong><?php echo htmlspecialchars($hospital['name']); ?></strong></p>
            </div>
            <a href="hospital.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
        </header>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card revenue">
                <div class="stat-label"><i class="fas fa-wallet"></i> Total Revenue</div>
                <div class="stat-value">PKR <?php echo number_format($stats['total_revenue'] ?? 0, 0); ?></div>
            </div>
            <div class="stat-card parents">
                <div class="stat-label"><i class="fas fa-users"></i> Parents Served</div>
                <div class="stat-value"><?php echo $stats['unique_parents'] ?? 0; ?></div>
            </div>
            <div class="stat-card transactions">
                <div class="stat-label"><i class="fas fa-exchange-alt"></i> Transactions</div>
                <div class="stat-value"><?php echo $stats['total_payments'] ?? 0; ?></div>
            </div>
            <div class="stat-card rating">
                <div class="stat-label"><i class="fas fa-star"></i> Hospital Rating</div>
                <div class="stat-value" style="font-size: 24px;">
                    <?php echo number_format($stats['avg_rating'] ?? 0, 1); ?>
                </div>
                <div class="rating-display-stat">
                    <?php 
                    $rating = (float)($stats['avg_rating'] ?? 0);
                    echo str_repeat('★', (int)$rating) . str_repeat('☆', 5 - (int)$rating);
                    echo " (" . ($stats['total_ratings'] ?? 0) . " reviews)";
                    ?>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="content-grid">
            <div class="section">
                <h2><i class="fas fa-chart-line"></i> Monthly Revenue Trend</h2>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="section">
                <h2><i class="fas fa-list"></i> Top Paying Parents</h2>
                <?php
                if ($parent_breakdown_result->num_rows > 0) {
                    while ($parent = $parent_breakdown_result->fetch_assoc()) {
                        echo "
                        <div class='parent-item'>
                            <div>
                                <div class='parent-name'>{$parent['name']}</div>
                                <div class='parent-email'>{$parent['email']}</div>
                            </div>
                            <div class='parent-transactions'>{$parent['transaction_count']} transaction(s)</div>
                            <div class='parent-amount'>PKR " . number_format($parent['total_amount'], 0) . "</div>
                        </div>
                        ";
                    }
                } else {
                    echo "<div class='no-data'>No payments yet</div>";
                }
                ?>
            </div>
        </div>

        <!-- QR Verification -->
        <div class="section">
            <h2><i class="fas fa-qrcode"></i> QR Code Verification</h2>
            <div class="qr-form">
                <form method="POST">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 700; font-size: 14px;">
                            <i class="fas fa-paste"></i> Paste QR Code Data
                        </label>
                        <textarea name="qr_data" placeholder="Paste the QR code JSON data here..." required></textarea>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                    <button type="submit" name="verify_qr" class="btn">
                        <i class="fas fa-check-circle"></i> Verify Payment
                    </button>
                </form>
            </div>

            <?php if ($qr_error) { ?>
            <div class="error-msg">
                <?php echo htmlspecialchars($qr_error); ?>
            </div>
            <?php } ?>

            <?php if ($qr_verification_result) { ?>
            <div class="qr-result">
                <h3 style="margin-bottom: 15px; color: #10b981; font-weight: 700;">
                    <i class="fas fa-check-circle"></i> Verification Successful!
                </h3>
                <div class="qr-info">
                    <div class="qr-row">
                        <div class="qr-label">Parent Name:</div>
                        <div class="qr-value"><?php echo htmlspecialchars($qr_verification_result['parent_name']); ?></div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Email:</div>
                        <div class="qr-value"><?php echo htmlspecialchars($qr_verification_result['email']); ?></div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Phone:</div>
                        <div class="qr-value"><?php echo htmlspecialchars($qr_verification_result['phone']); ?></div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Child Name:</div>
                        <div class="qr-value"><?php echo htmlspecialchars($qr_verification_result['child_name']); ?></div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Appointment:</div>
                        <div class="qr-value">
                            <?php echo date('d M Y', strtotime($qr_verification_result['booking_date'])); ?>
                            at <?php echo date('H:i', strtotime($qr_verification_result['booking_time'])); ?>
                        </div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Vaccines:</div>
                        <div class="qr-value"><?php echo htmlspecialchars($qr_verification_result['vaccines']); ?></div>
                    </div>
                    <div class="qr-row">
                        <div class="qr-label">Payment Status:</div>
                        <div class="qr-value">
                            <span class="payment-status-badge badge-<?php echo ($qr_verification_result['payment_status'] === 'paid' ? 'paid' : 'unpaid'); ?>">
                                <?php echo strtoupper($qr_verification_result['payment_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Hospital Reviews -->
        <div class="section">
            <h2><i class="fas fa-comments"></i> Parent Reviews (<?php echo $stats['total_ratings'] ?? 0; ?>)</h2>
            <?php
            if ($ratings->num_rows > 0) {
                while ($rating = $ratings->fetch_assoc()) {
                    echo "
                    <div class='rating-item'>
                        <div class='rating-header'>
                            <span class='rating-name'>{$rating['parent_name']}</span>
                            <span class='rating-date'>" . date('d M Y', strtotime($rating['created_at'])) . "</span>
                        </div>
                        <div class='rating-stars'>" . str_repeat('★', $rating['stars']) . str_repeat('☆', 5 - $rating['stars']) . " ({$rating['stars']}/5)</div>
                        " . ($rating['comment'] ? "<div class='rating-comment'>\"" . htmlspecialchars($rating['comment']) . "\"</div>" : "") . "
                    </div>
                    ";
                }
            } else {
                echo "<div class='no-data'>No reviews yet</div>";
            }
            ?>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal. All rights reserved.</p>
    </footer>

    <script>
        // Revenue Chart
        const revenueCtx = document.getElementById('revenueChart');
        if (revenueCtx) {
            new Chart(revenueCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthly_labels); ?>,
                    datasets: [{
                        label: 'Revenue (PKR)',
                        data: <?php echo json_encode($monthly_amounts); ?>,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, labels: { font: { size: 12 } } }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: v => 'PKR ' + v } }
                    }
                }
            });
        }
    </script>
</body>
</html>
