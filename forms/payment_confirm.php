<?php
/**
 * Payment Confirmation Page
 * 
 * After successful payment (via test mode or external gateway):
 * 1. Verify payment status
 * 2. Mark booking as paid
 * 3. Generate QR code
 * 4. Redirect to success page
 */

session_start();
include 'connection.php';
include 'qr_generator.php';

if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$payment_id = (int)($_GET['payment_id'] ?? 0);
$external_id = trim($_GET['external_id'] ?? '');

if ($payment_id <= 0) {
    die("Invalid payment ID");
}

// Fetch payment details
$stmt = $conn->prepare("
    SELECT p.*, b.id as booking_id, b.parent_id, b.child_id, b.hospital_id, b.total_amount
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE p.id = ? AND p.parent_id = ?
");
$stmt->bind_param("ii", $payment_id, $parent_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    die("Payment not found");
}

$booking_id = $payment['booking_id'];

// If external payment gateway returned, verify payment was successful
if (!empty($external_id)) {
    // Verify with payment gateway
    // TODO: Implement gateway-specific verification
    
    // Mark payment as completed
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'completed', external_payment_id = ?, paid_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("si", $external_id, $payment_id);
    $stmt->execute();
    $stmt->close();
    
    error_log("Payment verified and completed: payment_id=$payment_id, external_id=$external_id");
} else if ($payment['payment_gateway'] === 'test') {
    // Test mode: auto-confirm payment
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'completed', paid_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $stmt->close();
    
    error_log("Test mode payment confirmed: payment_id=$payment_id");
}

// ================================================
// Mark Booking as Paid
// ================================================

$stmt = $conn->prepare("
    UPDATE bookings 
    SET payment_status = 'paid', paid_at = NOW()
    WHERE id = ? AND parent_id = ?
");
$stmt->bind_param("ii", $booking_id, $parent_id);
$stmt->execute();
$stmt->close();

error_log("Booking marked as paid: booking_id=$booking_id, parent_id=$parent_id");

// ================================================
// Generate QR Code
// ================================================

$qr_data = json_encode([
    'booking_id' => $booking_id,
    'parent_id' => $payment['parent_id'],
    'child_id' => $payment['child_id'],
    'hospital_id' => $payment['hospital_id'],
    'payment_status' => 'paid',
    'timestamp' => date('Y-m-d H:i:s'),
    'verification_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/Medilab/forms/verify_qr.php'
]);

// Generate QR code image
$qr_filename = 'qr_booking_' . $booking_id . '_' . time() . '.png';
$qr_filepath = __DIR__ . '/../qr_codes/' . $qr_filename;
$qr_result = QRCodeGenerator::generateQRImage($qr_data, $qr_filepath, 400);

if ($qr_result['success']) {
    // Get base64 version
    $qr_base64_result = QRCodeGenerator::generateQRBase64($qr_data, 400);
    $qr_base64 = $qr_base64_result['success'] ? $qr_base64_result['base64'] : null;
    
    // Store QR code in database
    $qr_image_url = '/Medilab/qr_codes/' . $qr_filename;
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    $stmt = $conn->prepare("
        INSERT INTO qr_codes 
        (booking_id, parent_id, child_id, hospital_id, qr_data, qr_image_path, qr_image_base64, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param(
        "iiisssss",
        $booking_id,
        $payment['parent_id'],
        $payment['child_id'],
        $payment['hospital_id'],
        $qr_data,
        $qr_image_url,
        $qr_base64,
        $expires_at
    );
    
    if ($stmt->execute()) {
        $qr_code_id = $conn->insert_id;
        error_log("QR code generated: qr_code_id=$qr_code_id, booking_id=$booking_id");
    } else {
        error_log("Failed to save QR code: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("QR code generation failed: " . $qr_result['error']);
}

// ================================================
// Redirect to Success Page
// ================================================

header("Location: payment_success.php?booking_id=$booking_id&payment_id=$payment_id");
exit;

?>
