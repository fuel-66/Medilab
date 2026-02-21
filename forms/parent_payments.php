<?php
/**
 * Parent Payment History & Hospital Ratings - Premium UI
 * Complete payment analytics with interactive charts and hospital ratings
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];

// Fetch parent info
$stmt = $conn->prepare("SELECT id, name, email, phone FROM parents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle star rating submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }
    
    $hospital_id = (int)($_POST['hospital_id'] ?? 0);
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $stars = (int)($_POST['stars'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    
    if ($hospital_id > 0 && $booking_id > 0 && $stars >= 1 && $stars <= 5) {
        $stmt = $conn->prepare("
            INSERT INTO hospital_ratings (parent_id, hospital_id, booking_id, stars, comment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE stars = ?, comment = ?, created_at = NOW()
        ");
        $stmt->bind_param("iiissis", $parent_id, $hospital_id, $booking_id, $stars, $comment, $stars, $comment);
        
        if ($stmt->execute()) {
            $feedback = "✓ Thank you for rating the hospital!";
        } else {
            $feedback = "❌ Error saving rating: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Fetch payment history
$payment_query = "
    SELECT 
        p.id,
        p.amount,
        p.status,
        p.payment_gateway,
        p.created_at,
        p.paid_at,
        b.id as booking_id,
        h.id as hospital_id,
        h.name as hospital_name,
        h.address as hospital_address,
        c.name as child_name,
        GROUP_CONCAT(v.name SEPARATOR ', ') as vaccines
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN hospitals h ON b.hospital_id = h.id
    JOIN children c ON b.child_id = c.id
    JOIN booking_vaccinations bv ON b.id = bv.booking_id
    JOIN vaccines v ON bv.vaccine_id = v.id
    WHERE p.parent_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
";

$stmt = $conn->prepare($payment_query);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$payments = $stmt->get_result();
$stmt->close();

// Calculate statistics
$stats_query = "
    SELECT 
        SUM(CASE WHEN p.status = 'completed' THEN p.amount ELSE 0 END) as total_paid,
        COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN b.hospital_id ELSE NULL END) as hospitals_count,
        COUNT(*) as total_payments,
        SUM(CASE WHEN p.status = 'pending' THEN p.amount ELSE 0 END) as pending_amount
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE p.parent_id = ?
";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get hospital breakdown for chart
$hospital_breakdown = "
    SELECT 
        h.id,
        h.name,
        SUM(p.amount) as total_amount,
        COUNT(p.id) as transaction_count
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN hospitals h ON b.hospital_id = h.id
    WHERE p.parent_id = ? AND p.status = 'completed'
    GROUP BY h.id
    ORDER BY total_amount DESC
";

$stmt = $conn->prepare($hospital_breakdown);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$hospital_breakdown_result = $stmt->get_result();
$stmt->close();

// Get vaccine cost breakdown for chart
$vaccine_breakdown = "
    SELECT 
        v.name,
        v.type,
        COUNT(bv.id) as count,
        SUM(p.amount) as total_cost
    FROM booking_vaccinations bv
    JOIN vaccines v ON bv.vaccine_id = v.id
    JOIN bookings b ON bv.booking_id = b.id
    JOIN payments p ON b.id = p.booking_id
    WHERE b.parent_id = ? AND p.status = 'completed'
    GROUP BY v.id
    ORDER BY total_cost DESC
    LIMIT 6
";

$stmt = $conn->prepare($vaccine_breakdown);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$vaccine_breakdown_result = $stmt->get_result();
$stmt->close();

// Get all hospitals for rating selection (from all bookings)
$hospitals_with_bookings = "
    SELECT DISTINCT 
        h.id as hospital_id,
        h.name as hospital_name
    FROM bookings b
    JOIN hospitals h ON b.hospital_id = h.id
    WHERE b.parent_id = ?
    GROUP BY h.id
    ORDER BY h.name
";

$stmt = $conn->prepare($hospitals_with_bookings);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$hospitals_for_rating = $stmt->get_result();
$stmt->close();

// Get ratings given by parent
$ratings_query = "
    SELECT 
        hr.id,
        hr.hospital_id,
        hr.stars,
        hr.comment,
        hr.created_at,
        h.name as hospital_name
    FROM hospital_ratings hr
    JOIN hospitals h ON hr.hospital_id = h.id
    WHERE hr.parent_id = ?
    ORDER BY hr.created_at DESC
";

$stmt = $conn->prepare($ratings_query);
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$ratings = $stmt->get_result();
$stmt->close();

// Prepare data for charts
$hospital_labels = [];
$hospital_amounts = [];
$hospital_colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

$temp_result = $conn->prepare($hospital_breakdown);
$temp_result->bind_param("i", $parent_id);
$temp_result->execute();
$temp_data = $temp_result->get_result();

while ($row = $temp_data->fetch_assoc()) {
    $hospital_labels[] = $row['name'];
    $hospital_amounts[] = (int)$row['total_amount'];
}
$temp_result->close();

$vaccine_labels = [];
$vaccine_amounts = [];
$temp_result = $conn->prepare($vaccine_breakdown);
$temp_result->bind_param("i", $parent_id);
$temp_result->execute();
$temp_data = $temp_result->get_result();

while ($row = $temp_data->fetch_assoc()) {
    $vaccine_labels[] = $row['type'];
    $vaccine_amounts[] = (int)$row['total_cost'];
}
$temp_result->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History & Hospital Reviews</title>
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

        .stat-card.paid {
            border-color: #10b981;
        }

        .stat-card.pending {
            border-color: #f59e0b;
        }

        .stat-card.hospitals {
            border-color: #3b82f6;
        }

        .stat-card.transactions {
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

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .chart-card h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #1f2937;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 320px;
        }

        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
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

        .payment-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 15px;
            padding: 18px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 12px;
            align-items: center;
            transition: all 0.3s;
        }

        .payment-item:hover {
            background: #f9fafb;
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }

        .payment-hospital {
            font-weight: 700;
            color: #1f2937;
            font-size: 15px;
        }

        .payment-vaccines {
            color: #6b7280;
            font-size: 13px;
            margin-top: 5px;
        }

        .payment-amount {
            font-weight: 700;
            font-size: 16px;
            color: #667eea;
            text-align: right;
        }

        .payment-status {
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            text-align: center;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        @media (max-width: 768px) {
            .payment-item {
                grid-template-columns: 1fr;
            }
        }

        .rating-form {
            background: #f9fafb;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            border: 2px solid #e5e7eb;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #1f2937;
            font-size: 14px;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .star-rating {
            display: flex;
            gap: 12px;
            font-size: 32px;
        }

        .star {
            cursor: pointer;
            color: #d1d5db;
            transition: all 0.2s;
        }

        .star:hover,
        .star.active {
            color: #fbbf24;
            transform: scale(1.25);
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .feedback {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            border-left: 4px solid;
        }

        .feedback-success {
            background: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }

        .feedback-error {
            background: #fee2e2;
            color: #991b1b;
            border-color: #ef4444;
        }

        .rating-display {
            background: #f9fafb;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 12px;
            border-left: 4px solid #fbbf24;
            transition: all 0.3s;
        }

        .rating-display:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .rating-hospital {
            font-weight: 700;
            color: #1f2937;
            font-size: 15px;
        }

        .rating-date {
            font-size: 12px;
            color: #9ca3af;
        }

        .rating-stars {
            color: #fbbf24;
            font-size: 16px;
            margin-bottom: 8px;
        }

        .rating-comment {
            color: #4b5563;
            font-size: 13px;
            font-style: italic;
            margin-top: 8px;
        }

        .no-data {
            text-align: center;
            color: #9ca3af;
            padding: 40px 20px;
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
                <h1><i class="fas fa-credit-card"></i> Payment History</h1>
                <p style="color: #6b7280; margin-top: 8px;">Welcome, <strong><?php echo htmlspecialchars($parent['name']); ?></strong></p>
            </div>
            <a href="parent.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
        </header>

        <?php if (isset($feedback)) { ?>
        <div class="feedback <?php echo strpos($feedback, '✓') !== false ? 'feedback-success' : 'feedback-error'; ?>">
            <?php echo htmlspecialchars($feedback); ?>
        </div>
        <?php } ?>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card paid">
                <div class="stat-label"><i class="fas fa-check-circle"></i> Total Paid</div>
                <div class="stat-value">PKR <?php echo number_format($stats['total_paid'] ?? 0, 0); ?></div>
            </div>
            <div class="stat-card pending">
                <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pending</div>
                <div class="stat-value">PKR <?php echo number_format($stats['pending_amount'] ?? 0, 0); ?></div>
            </div>
            <div class="stat-card hospitals">
                <div class="stat-label"><i class="fas fa-hospital"></i> Hospitals Visited</div>
                <div class="stat-value"><?php echo $stats['hospitals_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card transactions">
                <div class="stat-label"><i class="fas fa-exchange-alt"></i> Transactions</div>
                <div class="stat-value"><?php echo $stats['total_payments'] ?? 0; ?></div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="content-grid">
            <?php if (!empty($hospital_labels)) { ?>
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie"></i> Spending by Hospital</h3>
                <div class="chart-container">
                    <canvas id="hospitalChart"></canvas>
                </div>
            </div>
            <?php } ?>
            
            <?php if (!empty($vaccine_labels)) { ?>
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Vaccine Costs</h3>
                <div class="chart-container">
                    <canvas id="vaccineChart"></canvas>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Payment History Section -->
        <?php if ($payments->num_rows > 0) { ?>
        <div class="section">
            <h2><i class="fas fa-receipt"></i> Payment History</h2>
            <?php 
            $payments->data_seek(0);
            while ($payment = $payments->fetch_assoc()) {
                $status_class = 'status-' . $payment['status'];
                echo "
                <div class='payment-item'>
                    <div>
                        <div class='payment-hospital'>{$payment['hospital_name']}</div>
                        <div class='payment-vaccines'><strong>{$payment['child_name']}</strong> - {$payment['vaccines']}</div>
                    </div>
                    <div style='text-align: center;'>
                        <div style='font-size: 13px; color: #6b7280;'>" . date('d M Y', strtotime($payment['created_at'])) . "</div>
                        <div style='font-size: 12px; color: #667eea; margin-top: 5px; font-weight: 600;'>{$payment['payment_gateway']}</div>
                    </div>
                    <div class='payment-amount'>PKR " . number_format($payment['amount'], 0) . "</div>
                    <span class='payment-status {$status_class}'>" . strtoupper($payment['status']) . "</span>
                </div>
                ";
            }
            ?>
        </div>
        <?php } ?>

        <!-- Hospital Ratings Section -->
        <div class="section">
            <h2><i class="fas fa-star"></i> Hospital Reviews & Ratings</h2>

            <div class="rating-form">
                <h3 style="margin-bottom: 20px; font-size: 16px; font-weight: 700;"><i class="fas fa-pencil-alt"></i> Leave a Review</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="hospital_select"><i class="fas fa-hospital"></i> Select Hospital *</label>
                        <select id="hospital_select" name="hospital_id" required onchange="updateBookingsForHospital()">
                            <option value="">-- Choose a hospital --</option>
                            <?php
                            $hospitals_result = $conn->prepare($hospitals_with_bookings);
                            $hospitals_result->bind_param("i", $parent_id);
                            $hospitals_result->execute();
                            $hosp_data = $hospitals_result->get_result();
                            
                            $hospitals_array = [];
                            while ($hosp = $hosp_data->fetch_assoc()) {
                                $hospitals_array[$hosp['hospital_id']] = [
                                    'name' => $hosp['hospital_name']
                                ];
                                echo "<option value='{$hosp['hospital_id']}'>{$hosp['hospital_name']}</option>";
                            }
                            $hospitals_result->close();
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="booking_select"><i class="fas fa-calendar"></i> Select Booking *</label>
                        <select id="booking_select" name="booking_id" required>
                            <option value="">-- Choose a booking --</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-star"></i> Rating (1-5 Stars) *</label>
                        <div class="star-rating">
                            <span class="star" onclick="setRating(1)" data-value="1">★</span>
                            <span class="star" onclick="setRating(2)" data-value="2">★</span>
                            <span class="star" onclick="setRating(3)" data-value="3">★</span>
                            <span class="star" onclick="setRating(4)" data-value="4">★</span>
                            <span class="star" onclick="setRating(5)" data-value="5">★</span>
                        </div>
                        <input type="hidden" name="stars" id="stars" value="0">
                    </div>

                    <div class="form-group">
                        <label for="comment"><i class="fas fa-comments"></i> Comment (Optional)</label>
                        <textarea id="comment" name="comment" placeholder="Share your experience with this hospital..."></textarea>
                    </div>

                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                    <button type="submit" name="submit_rating" class="btn">
                        <i class="fas fa-paper-plane"></i> Submit Rating
                    </button>
                </form>
            </div>

            <?php if ($ratings->num_rows > 0) { ?>
            <div style="margin-top: 25px;">
                <h3 style="margin-bottom: 15px; font-size: 16px; font-weight: 700;"><i class="fas fa-history"></i> Your Reviews</h3>
                <?php
                $ratings->data_seek(0);
                while ($rating = $ratings->fetch_assoc()) {
                    echo "
                    <div class='rating-display'>
                        <div class='rating-header'>
                            <span class='rating-hospital'>{$rating['hospital_name']}</span>
                            <span class='rating-date'>" . date('d M Y', strtotime($rating['created_at'])) . "</span>
                        </div>
                        <div class='rating-stars'>" . str_repeat('★', $rating['stars']) . str_repeat('☆', 5 - $rating['stars']) . " ({$rating['stars']}/5)</div>
                        " . ($rating['comment'] ? "<div class='rating-comment'>\"" . htmlspecialchars($rating['comment']) . "\"</div>" : "") . "
                    </div>
                    ";
                }
                if ($ratings->num_rows === 0) {
                    echo "<p style=\"color: #9ca3af; font-style: italic; margin-top: 15px;\">No ratings yet. Be the first to rate a hospital above!</p>";
                }
                ?>
            </div>
            <?php } ?>
        </div>
    </div>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal. All rights reserved.</p>
    </footer>

    <script>
        const hospitalsData = <?php echo json_encode($hospitals_array ?? []); ?>;

        function setRating(value) {
            document.getElementById('stars').value = value;
            document.querySelectorAll('.star').forEach((star, idx) => {
                star.classList.toggle('active', idx < value);
            });
        }

        function updateBookingsForHospital() {
            const hospitalId = parseInt(document.getElementById('hospital_select').value);
            const bookingSelect = document.getElementById('booking_select');
            
            bookingSelect.innerHTML = '<option value="">-- Choose a booking --</option>';
            
            if (!hospitalId) return;

            // Fetch bookings from API
            fetch('get_bookings_for_hospital.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'hospital_id=' + hospitalId
            })
            .then(r => r.json())
            .then(data => {
                if (data.bookings && data.bookings.length > 0) {
                    data.bookings.forEach(booking => {
                        bookingSelect.innerHTML += `<option value="${booking.id}">${booking.date} - ${booking.vaccines}</option>`;
                    });
                }
            })
            .catch(err => console.error('Error fetching bookings:', err));
        }

        // Hospital Chart
        const hospitalCtx = document.getElementById('hospitalChart');
        if (hospitalCtx) {
            new Chart(hospitalCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($hospital_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($hospital_amounts); ?>,
                        backgroundColor: ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 15 } }
                    }
                }
            });
        }

        // Vaccine Chart
        const vaccineCtx = document.getElementById('vaccineChart');
        if (vaccineCtx) {
            new Chart(vaccineCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($vaccine_labels); ?>,
                    datasets: [{
                        label: 'Cost (PKR)',
                        data: <?php echo json_encode($vaccine_amounts); ?>,
                        backgroundColor: '#667eea',
                        borderRadius: 8,
                        borderSkipped: false
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
        }
    </script>
</body>
</html>
