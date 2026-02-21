<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$booking_id = (int)($_GET['booking_id'] ?? 0);
$payment_id = (int)($_GET['payment_id'] ?? 0);

if ($booking_id <= 0 || $payment_id <= 0) {
    die("Invalid parameters");
}

// Fetch booking and payment details
$stmt = $conn->prepare("
    SELECT 
        b.id, b.vaccine_type, b.booking_date, b.booking_time, b.total_amount,
        c.name as child_name,
        h.name as hospital_name,
        p.status as payment_status, p.created_at as payment_date
    FROM bookings b
    JOIN children c ON b.child_id = c.id
    JOIN hospitals h ON b.hospital_id = h.id
    JOIN payments p ON p.booking_id = b.id
    WHERE b.id = ? AND b.parent_id = ? AND p.id = ?
");
$stmt->bind_param("iii", $booking_id, $parent_id, $payment_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Booking or payment not found");
}

$booking = $result->fetch_assoc();
$stmt->close();

// Fetch QR code
$stmt = $conn->prepare("
    SELECT id, qr_image_path, qr_image_base64 
    FROM qr_codes 
    WHERE booking_id = ? AND parent_id = ?
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("ii", $booking_id, $parent_id);
$stmt->execute();
$qr = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch vaccinations in booking
$stmt = $conn->prepare("
    SELECT vaccine_name, vaccine_type, price, quantity
    FROM booking_vaccinations
    WHERE booking_id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$vaccinations = $stmt->get_result();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Success - Medilab</title>
    <link rel="stylesheet" href="parent.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payment-success-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .success-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 50px;
            color: white;
            animation: scaleIn 0.5s ease;
        }
        
        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }
        
        .success-header h1 {
            color: #10b981;
            margin: 10px 0;
            font-size: 32px;
        }
        
        .success-header p {
            color: #6b7280;
            font-size: 16px;
        }
        
        .booking-details {
            background: #f3f4f6;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #6b7280;
            font-weight: 500;
        }
        
        .detail-value {
            color: #1f2937;
            font-weight: 600;
        }
        
        .qr-section {
            text-align: center;
            margin: 40px 0;
            padding: 30px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .qr-section h3 {
            margin-bottom: 20px;
            color: #1f2937;
        }
        
        .qr-image {
            max-width: 300px;
            border: 3px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: white;
        }
        
        .vaccinations-list {
            margin: 20px 0;
        }
        
        .vaccine-item {
            padding: 12px;
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        .vaccine-item strong {
            color: #92400e;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn.primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn.primary:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn.secondary {
            background: #e5e7eb;
            color: #1f2937;
        }
        
        .btn.secondary:hover {
            background: #d1d5db;
        }
        
        .info-box {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
            color: #1e40af;
        }
        
        .print-receipt {
            border-top: 2px solid #e5e7eb;
            margin-top: 30px;
            padding-top: 20px;
        }
        
        @media print {
            body {
                background: white;
            }
            .action-buttons {
                display: none;
            }
            .payment-success-container {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body class="parent-page">

<main class="wrap">
    <div class="payment-success-container">
        
        <!-- Success Header -->
        <div class="success-header">
            <div class="success-icon">✓</div>
            <h1>Payment Successful!</h1>
            <p>Your booking has been confirmed and payment processed.</p>
        </div>
        
        <!-- Booking Details -->
        <div class="booking-details">
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-id-card"></i> Booking ID</span>
                <span class="detail-value">#<?php echo htmlspecialchars($booking['id']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-child"></i> Child Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['child_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-hospital"></i> Hospital</span>
                <span class="detail-value"><?php echo htmlspecialchars($booking['hospital_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-calendar"></i> Appointment Date</span>
                <span class="detail-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?> at <?php echo date('H:i', strtotime($booking['booking_time'])); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-rupiah"></i> Total Paid</span>
                <span class="detail-value" style="color: #10b981; font-size: 18px;">PKR <?php echo number_format($booking['total_amount'], 2); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-check-circle"></i> Status</span>
                <span class="detail-value"><span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 14px;">PAID</span></span>
            </div>
        </div>
        
        <!-- Vaccinations -->
        <?php if ($vaccinations->num_rows > 0): ?>
        <div class="vaccinations-list">
            <h3><i class="fas fa-syringe"></i> Vaccinations Booked</h3>
            <?php while ($vac = $vaccinations->fetch_assoc()): ?>
            <div class="vaccine-item">
                <strong><?php echo htmlspecialchars($vac['vaccine_name']); ?></strong>
                <span style="float: right; color: #92400e;">PKR <?php echo number_format($vac['price'], 2); ?></span>
                <br><small>Type: <?php echo htmlspecialchars($vac['vaccine_type']); ?></small>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- QR Code -->
        <?php if ($qr): ?>
        <div class="qr-section">
            <h3><i class="fas fa-qrcode"></i> Your Booking QR Code</h3>
            <p>Present this QR code at the hospital for verification</p>
            <?php if ($qr['qr_image_base64']): ?>
                <img src="data:image/png;base64,<?php echo $qr['qr_image_base64']; ?>" alt="Booking QR Code" class="qr-image">
            <?php elseif ($qr['qr_image_path']): ?>
                <img src="<?php echo htmlspecialchars($qr['qr_image_path']); ?>" alt="Booking QR Code" class="qr-image">
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Info Box -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <strong>Important:</strong> Please save or download this QR code. Present it at the hospital on your appointment date. The hospital will scan this code to verify your payment and complete the vaccination.
        </div>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <?php if ($qr && $qr['qr_image_path']): ?>
            <a href="<?php echo htmlspecialchars($qr['qr_image_path']); ?>" download="booking_qr_<?php echo $booking['id']; ?>.png" class="btn primary">
                <i class="fas fa-download"></i> Download QR
            </a>
            <?php endif; ?>
            <a href="parent.php" class="btn secondary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>
        </div>
        
        <!-- Print Receipt Section -->
        <div class="print-receipt">
            <p style="text-align: center; font-size: 12px; color: #9ca3af;">
                Receipt generated on <?php echo date('M d, Y H:i:s'); ?><br>
                Payment Reference: #<?php echo htmlspecialchars($payment_id); ?>
            </p>
        </div>
        
    </div>
</main>

</body>
</html>
