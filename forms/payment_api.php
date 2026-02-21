<?php
/**
 * Payment Processing API Endpoint
 * 
 * Handles:
 * 1. Creating payment records in database
 * 2. Processing payment via gateway (pluggable)
 * 3. Marking booking as paid
 * 4. Triggering QR code generation
 * 
 * Security:
 * - Session validation
 * - CSRF token check
 * - Parent ownership verification
 * - Idempotency checks (prevent double payment)
 */

session_start();
include 'connection.php';
include 'csrf.php';
include 'qr_generator.php';

header('Content-Type: application/json');

// =========================================
// 1. AUTHENTICATION & AUTHORIZATION
// =========================================

if (!isset($_SESSION['parent_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please login first.']);
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// =========================================
// 2. VALIDATE REQUEST
// =========================================

$action = trim($_POST['action'] ?? '');

// Different actions
switch ($action) {
    case 'process_payment':
        handleProcessPayment($conn, $parent_id);
        break;
    
    case 'verify_payment':
        handleVerifyPayment($conn, $parent_id);
        break;
    
    case 'cancel_payment':
        handleCancelPayment($conn, $parent_id);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}

// =========================================
// 3. HANDLE: Process Payment
// =========================================

function handleProcessPayment($conn, $parent_id) {
    // Get input
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'card');
    $payment_account_id = (int)($_POST['payment_account_id'] ?? 0);
    $create_account = isset($_POST['create_account']);
    
    // Account creation details
    $account_email = trim($_POST['account_email'] ?? '');
    $account_phone = trim($_POST['account_phone'] ?? '');
    $account_password = trim($_POST['account_password'] ?? '');
    
    // Validate booking exists and belongs to parent
    $booking = validateBookingOwnership($conn, $booking_id, $parent_id);
    if (!$booking) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Booking not found or unauthorized']);
        return;
    }
    
    // Check if already paid
    if ($booking['payment_status'] === 'paid') {
        echo json_encode(['success' => false, 'error' => 'This booking is already paid']);
        return;
    }
    
    // Get total amount
    $total_amount = (float)$booking['total_amount'];
    if ($total_amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid payment amount']);
        return;
    }
    
    // Handle account creation if needed
    if ($create_account) {
        $account_result = createPaymentAccount($conn, $parent_id, $account_email, $account_phone, $account_password);
        if (!$account_result['success']) {
            http_response_code(400);
            echo json_encode($account_result);
            return;
        }
        $payment_account_id = $account_result['account_id'];
    }
    
    // Verify payment account exists and belongs to parent
    if ($payment_account_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid payment account required']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT id FROM payment_accounts WHERE id = ? AND parent_id = ?");
    $stmt->bind_param("ii", $payment_account_id, $parent_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Payment account not found or unauthorized']);
        $stmt->close();
        return;
    }
    $stmt->close();
    
    // =====================
    // Create Payment Record
    // =====================
    
    $transaction_id = 'TXN-' . $booking_id . '-' . time() . '-' . substr(md5(uniqid()), 0, 8);
    
    $stmt = $conn->prepare("
        INSERT INTO payments 
        (booking_id, payment_account_id, parent_id, amount, payment_method, transaction_id, 
         payment_gateway, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        return;
    }
    
    $payment_status = 'test'; // Default to test gateway for now
    $stmt->bind_param(
        "iiidsss",
        $booking_id,
        $payment_account_id,
        $parent_id,
        $total_amount,
        $payment_method,
        $transaction_id,
        $payment_status
    );
    
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create payment record']);
        $stmt->close();
        return;
    }
    
    $payment_id = $conn->insert_id;
    $stmt->close();
    
    error_log("Payment created: payment_id=$payment_id, booking_id=$booking_id, parent_id=$parent_id, amount=$total_amount");
    
    // =====================
    // Route to Payment Gateway
    // =====================
    
    $gateway_response = routeToPaymentGateway($payment_status, [
        'payment_id' => $payment_id,
        'booking_id' => $booking_id,
        'parent_id' => $parent_id,
        'payment_account_id' => $payment_account_id,
        'amount' => $total_amount,
        'currency' => 'PKR',
        'transaction_id' => $transaction_id,
        'return_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/Medilab/forms/payment_callback.php?payment_id=' . $payment_id,
        'notify_url' => 'http://' . $_SERVER['HTTP_HOST'] . '/Medilab/forms/payment_webhook.php'
    ]);
    
    echo json_encode($gateway_response);
}

// =========================================
// 4. HANDLE: Verify Payment
// =========================================

function handleVerifyPayment($conn, $parent_id) {
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    
    $stmt = $conn->prepare("
        SELECT p.id, p.booking_id, p.status, p.amount, b.parent_id 
        FROM payments p
        JOIN bookings b ON p.booking_id = b.id
        WHERE p.id = ? AND p.parent_id = ?
    ");
    $stmt->bind_param("ii", $payment_id, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        $stmt->close();
        return;
    }
    
    $payment = $result->fetch_assoc();
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'payment_id' => $payment['id'],
        'booking_id' => $payment['booking_id'],
        'status' => $payment['status'],
        'amount' => $payment['amount']
    ]);
}

// =========================================
// 5. HANDLE: Cancel Payment
// =========================================

function handleCancelPayment($conn, $parent_id) {
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    
    // Get payment
    $stmt = $conn->prepare("
        SELECT p.id, p.status FROM payments p 
        WHERE p.id = ? AND p.parent_id = ?
    ");
    $stmt->bind_param("ii", $payment_id, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        $stmt->close();
        return;
    }
    
    $payment = $result->fetch_assoc();
    $stmt->close();
    
    // Can only cancel pending or processing payments
    if (!in_array($payment['status'], ['pending', 'processing'])) {
        echo json_encode(['success' => false, 'error' => 'Cannot cancel this payment']);
        return;
    }
    
    // Update status
    $stmt = $conn->prepare("UPDATE payments SET status = 'failed' WHERE id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $stmt->close();
    
    error_log("Payment cancelled: payment_id=$payment_id");
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment cancelled'
    ]);
}

// =========================================
// HELPER FUNCTIONS
// =========================================

/**
 * Validate booking exists and belongs to parent
 */
function validateBookingOwnership($conn, $booking_id, $parent_id) {
    $stmt = $conn->prepare("
        SELECT b.id, b.parent_id, b.child_id, b.hospital_id, 
               b.payment_status, b.total_amount, b.status
        FROM bookings b
        WHERE b.id = ? AND b.parent_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

/**
 * Create payment account for parent
 */
function createPaymentAccount($conn, $parent_id, $email, $phone, $password) {
    // Validate input
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }
    
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters'];
    }
    
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM payment_accounts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        return ['success' => false, 'error' => 'Email already registered'];
    }
    $stmt->close();
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert account
    $stmt = $conn->prepare("
        INSERT INTO payment_accounts (parent_id, email, phone, password_hash, is_verified)
        VALUES (?, ?, ?, ?, 1)
    ");
    
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }
    
    $verified = 1; // Auto-verify for now (consider email verification in production)
    $stmt->bind_param("isss", $parent_id, $email, $phone, $password_hash);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create account: ' . $error];
    }
    
    $account_id = $conn->insert_id;
    $stmt->close();
    
    error_log("Payment account created: account_id=$account_id, parent_id=$parent_id, email=$email");
    
    return ['success' => true, 'account_id' => $account_id];
}

/**
 * Route payment to appropriate gateway
 * 
 * PLUGGABLE: Add logic for Stripe, JazzCash, Razorpay, etc.
 */
function routeToPaymentGateway($gateway, $payment_data) {
    switch ($gateway) {
        case 'stripe':
            return handleStripePayment($payment_data);
        
        case 'jazzcash':
            return handleJazzCashPayment($payment_data);
        
        case 'razorpay':
            return handleRazorpayPayment($payment_data);
        
        case 'test':
        default:
            // TEST MODE: Simulate successful payment
            return handleTestPayment($payment_data);
    }
}

/**
 * TEST GATEWAY: Simulate payment (for development/demo)
 */
function handleTestPayment($payment_data) {
    // In test mode, return payment page for manual completion
    return [
        'success' => true,
        'gateway' => 'test',
        'redirect_url' => 'payment_confirm.php?payment_id=' . $payment_data['payment_id'],
        'test_mode' => true,
        'message' => 'Ready for payment confirmation'
    ];
}

/**
 * STRIPE Integration Stub
 * TODO: Implement full Stripe integration
 * 
 * Steps:
 * 1. Create Stripe Payment Intent
 * 2. Return client secret
 * 3. Frontend handles Stripe Elements
 * 4. Webhook verifies payment
 */
function handleStripePayment($payment_data) {
    // TODO: Implement Stripe API call
    return [
        'success' => false,
        'error' => 'Stripe integration not yet implemented',
        'next_step' => 'Install Stripe SDK and implement'
    ];
}

/**
 * JAZZCASH Integration Stub (Pakistan Payment Gateway)
 * TODO: Implement full JazzCash integration
 * 
 * Steps:
 * 1. Generate secure hash
 * 2. Post to JazzCash payment page
 * 3. Wait for IPN callback
 */
function handleJazzCashPayment($payment_data) {
    // TODO: Implement JazzCash API call
    return [
        'success' => false,
        'error' => 'JazzCash integration not yet implemented',
        'next_step' => 'Configure JazzCash credentials and implement API'
    ];
}

/**
 * RAZORPAY Integration Stub (India Payment Gateway)
 * TODO: Implement full Razorpay integration
 * 
 * Steps:
 * 1. Create Razorpay Order
 * 2. Return order details to frontend
 * 3. Verify payment signature on success
 */
function handleRazorpayPayment($payment_data) {
    // TODO: Implement Razorpay API call
    return [
        'success' => false,
        'error' => 'Razorpay integration not yet implemented',
        'next_step' => 'Configure Razorpay credentials and implement API'
    ];
}

?>
