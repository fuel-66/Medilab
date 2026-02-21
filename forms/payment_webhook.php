<?php
/**
 * Payment Webhook Handler
 * 
 * Handles POST callbacks from payment gateways:
 * - Stripe: payment_intent.succeeded, charge.dispute.created, etc.
 * - JazzCash: IPN (Instant Payment Notification)
 * - Razorpay: Webhook for payment.authorized, payment.failed, etc.
 * 
 * Security:
 * - Verify webhook signature
 * - Log all webhook calls
 * - Idempotent processing (prevent double processing)
 * - No CSRF token needed (gateway posts to this)
 */

session_start();
include 'connection.php';

header('Content-Type: application/json');

// Log all incoming webhooks
error_log("Payment webhook received: " . json_encode($_REQUEST));

// ================================================
// 1. IDENTIFY PAYMENT GATEWAY
// ================================================

$gateway = detectPaymentGateway();

if (!$gateway) {
    error_log("Unable to detect payment gateway from webhook");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid webhook']);
    exit;
}

// ================================================
// 2. ROUTE TO APPROPRIATE HANDLER
// ================================================

switch ($gateway) {
    case 'stripe':
        handleStripeWebhook();
        break;
    
    case 'jazzcash':
        handleJazzCashWebhook();
        break;
    
    case 'razorpay':
        handleRazorpayWebhook();
        break;
    
    default:
        error_log("Unknown payment gateway: $gateway");
        http_response_code(400);
        echo json_encode(['error' => 'Unknown gateway']);
        exit;
}

// ================================================
// 3. GATEWAY DETECTION
// ================================================

function detectPaymentGateway() {
    // Stripe uses Authorization header
    if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
        return 'stripe';
    }
    
    // JazzCash sends pp_* parameters
    if (isset($_REQUEST['pp_ResponseCode']) || isset($_REQUEST['pp_Status'])) {
        return 'jazzcash';
    }
    
    // Razorpay uses X-Razorpay-Signature header
    if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
        return 'razorpay';
    }
    
    return null;
}

// ================================================
// 4. STRIPE WEBHOOK HANDLER
// ================================================

function handleStripeWebhook() {
    global $conn;
    
    $webhook_secret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';
    
    if (empty($webhook_secret)) {
        error_log("Stripe webhook secret not configured");
        http_response_code(400);
        echo json_encode(['error' => 'Webhook not configured']);
        return;
    }
    
    // Get the raw body for signature verification
    $raw_body = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    // Verify signature
    try {
        $event = \Stripe\Webhook::constructEvent($raw_body, $signature, $webhook_secret);
    } catch (\UnexpectedValueException $e) {
        error_log("Stripe webhook signature verification failed: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        error_log("Stripe webhook invalid: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    }
    
    // Process event based on type
    switch ($event->type) {
        
        case 'payment_intent.succeeded':
            handleStripePaymentSucceeded($event->data->object);
            break;
        
        case 'payment_intent.payment_failed':
            handleStripePaymentFailed($event->data->object);
            break;
        
        case 'charge.refunded':
            handleStripeRefund($event->data->object);
            break;
        
        case 'charge.dispute.created':
            handleStripeDispute($event->data->object);
            break;
        
        default:
            error_log("Stripe event not handled: " . $event->type);
    }
    
    // Return success to Stripe
    http_response_code(200);
    echo json_encode(['success' => true]);
}

function handleStripePaymentSucceeded($payment_intent) {
    global $conn;
    
    $payment_id = $payment_intent->metadata->payment_id ?? null;
    $booking_id = $payment_intent->metadata->booking_id ?? null;
    $external_id = $payment_intent->id;
    
    if (!$payment_id) {
        error_log("Stripe payment succeeded but no payment_id in metadata");
        return;
    }
    
    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'completed', 
            external_payment_id = ?,
            paid_at = NOW()
        WHERE id = ? AND status IN ('pending', 'processing')
    ");
    
    if (!$stmt) {
        error_log("Failed to prepare Stripe update: " . $conn->error);
        return;
    }
    
    $stmt->bind_param("si", $external_id, $payment_id);
    if (!$stmt->execute()) {
        error_log("Failed to update payment: " . $stmt->error);
        $stmt->close();
        return;
    }
    $stmt->close();
    
    // Mark booking as paid
    $stmt = $conn->prepare("
        UPDATE bookings 
        SET payment_status = 'paid', paid_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $stmt->close();
    
    // Generate QR code
    // ... (same logic as payment_confirm.php)
    
    error_log("Stripe payment succeeded: payment_id=$payment_id, external_id=$external_id");
}

function handleStripePaymentFailed($payment_intent) {
    global $conn;
    
    $payment_id = $payment_intent->metadata->payment_id ?? null;
    
    if (!$payment_id) {
        return;
    }
    
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'failed'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $stmt->close();
    
    error_log("Stripe payment failed: payment_id=$payment_id");
}

function handleStripeRefund($charge) {
    global $conn;
    
    // Find payment by external_payment_id
    $stmt = $conn->prepare("
        SELECT id, refund_amount FROM payments 
        WHERE external_payment_id = ?
    ");
    $stmt->bind_param("s", $charge->id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($payment) {
        $refund_amount = $charge->amount_refunded / 100; // Convert from cents
        
        $stmt = $conn->prepare("
            UPDATE payments 
            SET refund_amount = ?, status = 'refunded'
            WHERE id = ?
        ");
        $stmt->bind_param("di", $refund_amount, $payment['id']);
        $stmt->execute();
        $stmt->close();
        
        error_log("Stripe refund processed: payment_id={$payment['id']}, amount=$refund_amount");
    }
}

function handleStripeDispute($dispute) {
    error_log("Stripe dispute created: " . json_encode($dispute));
    // Notify admin, escalate to support
}

// ================================================
// 5. JAZZCASH WEBHOOK HANDLER (IPN)
// ================================================

function handleJazzCashWebhook() {
    global $conn;
    
    $pp_SecureHash = $_REQUEST['pp_SecureHash'] ?? '';
    $pp_Password = $_ENV['JAZZCASH_PASSWORD'] ?? '';
    
    // Verify hash
    $response_data = $_REQUEST;
    unset($response_data['pp_SecureHash']);
    
    $string_to_hash = strtoupper(
        implode('&', array_map(
            function($k, $v) { return "$k=$v"; },
            array_keys($response_data),
            array_values($response_data)
        ))
    );
    
    $expected_hash = hash_hmac('sha256', $string_to_hash, $pp_Password);
    
    if ($pp_SecureHash !== $expected_hash) {
        error_log("JazzCash hash verification failed");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid hash']);
        return;
    }
    
    $pp_ResponseCode = $_REQUEST['pp_ResponseCode'] ?? '';
    $pp_TxnRefNo = $_REQUEST['pp_TxnRefNo'] ?? '';
    $pp_Amount = $_REQUEST['pp_Amount'] ?? 0; // in paisa
    
    // Extract payment_id and booking_id from transaction reference
    // Format: payment_123_booking_456
    if (preg_match('/payment_(\d+)_booking_(\d+)/', $pp_TxnRefNo, $matches)) {
        $payment_id = $matches[1];
        $booking_id = $matches[2];
    } else {
        error_log("Unable to parse JazzCash transaction reference: $pp_TxnRefNo");
        return;
    }
    
    // Response codes:
    // 0 = Success
    // Non-zero = Failure
    
    if ($pp_ResponseCode === '0') {
        // Success
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'completed', external_payment_id = ?, paid_at = NOW()
            WHERE id = ? AND status IN ('pending', 'processing')
        ");
        $stmt->bind_param("si", $pp_TxnRefNo, $payment_id);
        $stmt->execute();
        $stmt->close();
        
        // Mark booking as paid
        $stmt = $conn->prepare("UPDATE bookings SET payment_status = 'paid', paid_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();
        
        error_log("JazzCash payment succeeded: payment_id=$payment_id, txn_ref=$pp_TxnRefNo");
    } else {
        // Failure
        $pp_ResponseMessage = $_REQUEST['pp_ResponseMessage'] ?? 'Unknown error';
        
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'failed', notes = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $pp_ResponseMessage, $payment_id);
        $stmt->execute();
        $stmt->close();
        
        error_log("JazzCash payment failed: payment_id=$payment_id, code=$pp_ResponseCode, msg=$pp_ResponseMessage");
    }
}

// ================================================
// 6. RAZORPAY WEBHOOK HANDLER
// ================================================

function handleRazorpayWebhook() {
    global $conn;
    
    $webhook_secret = $_ENV['RAZORPAY_WEBHOOK_SECRET'] ?? '';
    
    if (empty($webhook_secret)) {
        error_log("Razorpay webhook secret not configured");
        return;
    }
    
    $raw_body = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
    
    // Verify signature
    $expected_signature = hash_hmac('sha256', $raw_body, $webhook_secret);
    
    if ($signature !== $expected_signature) {
        error_log("Razorpay signature verification failed");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        return;
    }
    
    $event = json_decode($raw_body, true);
    $event_type = $event['event'] ?? '';
    $event_data = $event['payload']['payment']['entity'] ?? [];
    
    switch ($event_type) {
        
        case 'payment.authorized':
            $payment_id = $event_data['notes']['payment_id'] ?? null;
            $booking_id = $event_data['notes']['booking_id'] ?? null;
            $external_id = $event_data['id'] ?? null;
            
            if ($payment_id) {
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'completed', external_payment_id = ?, paid_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $external_id, $payment_id);
                $stmt->execute();
                $stmt->close();
                
                $stmt = $conn->prepare("UPDATE bookings SET payment_status = 'paid', paid_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $booking_id);
                $stmt->execute();
                $stmt->close();
                
                error_log("Razorpay payment authorized: payment_id=$payment_id, external_id=$external_id");
            }
            break;
        
        case 'payment.failed':
            $payment_id = $event_data['notes']['payment_id'] ?? null;
            $reason = $event_data['error_reason'] ?? 'Unknown';
            
            if ($payment_id) {
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'failed', notes = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $reason, $payment_id);
                $stmt->execute();
                $stmt->close();
                
                error_log("Razorpay payment failed: payment_id=$payment_id, reason=$reason");
            }
            break;
        
        case 'refund.created':
            $refund_id = $event_data['id'] ?? null;
            $external_id = $event_data['payment_id'] ?? null;
            $amount = $event_data['amount'] / 100; // Convert from paisa
            
            $stmt = $conn->prepare("
                UPDATE payments 
                SET refund_amount = ?, status = 'refunded'
                WHERE external_payment_id = ?
            ");
            $stmt->bind_param("ds", $amount, $external_id);
            $stmt->execute();
            $stmt->close();
            
            error_log("Razorpay refund: refund_id=$refund_id, amount=$amount");
            break;
    }
}

// ================================================
// 7. LOG FUNCTION
// ================================================

function logWebhookEvent($gateway, $event_type, $data) {
    error_log("[WEBHOOK] Gateway: $gateway | Type: $event_type | Data: " . json_encode($data));
}

?>
