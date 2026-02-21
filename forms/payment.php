<?php
/**
 * Payment Page
 * 
 * Displays:
 * - Booking details and vaccinations
 * - Total amount
 * - Payment account selection or creation form
 * - Payment method selection
 * - Proceed to payment button
 */

session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$error = "";
$success = "";

if ($booking_id <= 0) {
    header("Location: parent.php");
    exit;
}

// =====================================================
// FETCH BOOKING DETAILS
// =====================================================

$stmt = $conn->prepare("
    SELECT b.id, b.child_id, b.hospital_id, b.vaccine_type, 
           b.booking_date, b.booking_time, b.status, b.payment_status, b.total_amount,
           c.name as child_name,
           h.name as hospital_name
    FROM bookings b
    JOIN children c ON b.child_id = c.id
    JOIN hospitals h ON b.hospital_id = h.id
    WHERE b.id = ? AND b.parent_id = ?
");
$stmt->bind_param("ii", $booking_id, $parent_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: parent.php");
    exit;
}

// Check if already paid
if ($booking['payment_status'] === 'paid') {
    header("Location: parent.php?message=already_paid");
    exit;
}

// =====================================================
// CALCULATE TOTAL AMOUNT FROM VACCINES
// =====================================================

// Get booking vaccinations
$stmt = $conn->prepare("
    SELECT id, vaccine_name, vaccine_type, price, quantity
    FROM booking_vaccinations
    WHERE booking_id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$vaccines_result = $stmt->get_result();
$stmt->close();

$total_amount = 0;
$vaccines = [];
while ($row = $vaccines_result->fetch_assoc()) {
    $vaccines[] = $row;
    $total_amount += $row['price'] * $row['quantity'];
}

// If no vaccines in booking_vaccinations table, fall back to vaccine_type
if (empty($vaccines) && !empty($booking['vaccine_type'])) {
    // Query vaccines by type to get price
    $stmt = $conn->prepare("
        SELECT id, name as vaccine_name, type as vaccine_type, price
        FROM vaccines
        WHERE type = ? AND hospital_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $booking['vaccine_type'], $booking['hospital_id']);
    $stmt->execute();
    $vac = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($vac) {
        $vaccines[] = [
            'id' => $vac['id'],
            'vaccine_name' => $vac['vaccine_name'],
            'vaccine_type' => $vac['vaccine_type'],
            'price' => $vac['price'],
            'quantity' => 1
        ];
        $total_amount = $vac['price'];
    } else {
        // No vaccine price found - set default
        $vaccines[] = [
            'vaccine_name' => $booking['vaccine_type'],
            'vaccine_type' => $booking['vaccine_type'],
            'price' => 1000, // Default price if not in database
            'quantity' => 1
        ];
        $total_amount = 1000;
    }
}

// =====================================================
// FETCH PARENT'S EXISTING PAYMENT ACCOUNTS
// =====================================================

$stmt = $conn->prepare("
    SELECT id, email, phone, account_balance, is_verified
    FROM payment_accounts
    WHERE parent_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$accounts_result = $stmt->get_result();
$stmt->close();

$payment_accounts = [];
while ($row = $accounts_result->fetch_assoc()) {
    $payment_accounts[] = $row;
}

// =====================================================
// FETCH PARENT INFO
// =====================================================

$stmt = $conn->prepare("SELECT name, email, phone FROM parents WHERE id = ?");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - Medilab</title>
    <link rel="stylesheet" href="parent.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payment-page-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .payment-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .payment-header h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        
        .payment-header p {
            margin: 0;
            opacity: 0.9;
        }
        
        .payment-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .payment-layout {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        .card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h2 i {
            color: #667eea;
        }
        
        .vaccine-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .vaccine-info {
            flex: 1;
        }
        
        .vaccine-name {
            font-weight: 600;
            color: #1f2937;
        }
        
        .vaccine-type {
            font-size: 13px;
            color: #6b7280;
        }
        
        .vaccine-price {
            font-size: 16px;
            font-weight: 600;
            color: #667eea;
            margin-left: 20px;
        }
        
        .booking-info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .booking-info-row:last-child {
            border-bottom: none;
        }
        
        .booking-info-label {
            color: #6b7280;
            font-weight: 500;
        }
        
        .booking-info-value {
            color: #1f2937;
            font-weight: 600;
        }
        
        .total-amount-box {
            background: #fffbeb;
            border: 2px solid #fbbf24;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: right;
        }
        
        .total-label {
            font-size: 14px;
            color: #92400e;
            margin-bottom: 8px;
        }
        
        .total-value {
            font-size: 32px;
            font-weight: 700;
            color: #d97706;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1f2937;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .account-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .account-option {
            flex: 1;
            min-width: 150px;
            padding: 15px;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .account-option:hover {
            border-color: #667eea;
            background: #f3f4f6;
        }
        
        .account-option input[type="radio"] {
            display: none;
        }
        
        .account-option input[type="radio"]:checked + .account-label {
            color: #667eea;
        }
        
        .account-option input[type="radio"]:checked ~ * {
            color: #667eea;
        }
        
        .account-label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #1f2937;
        }
        
        .account-detail {
            font-size: 12px;
            color: #6b7280;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .tab-button {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: #6b7280;
            transition: all 0.3s ease;
        }
        
        .tab-button:hover {
            color: #1f2937;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn.secondary {
            background: #e5e7eb;
            color: #1f2937;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        
        .alert.error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        
        .alert.success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        
        .alert.info {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            color: #1e40af;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #10b981;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
</head>
<body class="parent-page">

<header class="topbar">
    <div class="brand">Medilab</div>
    <div class="header-actions">
        <a href="parent.php" class="logout-btn" style="background: var(--primary-blue);">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</header>

<main class="payment-page-container">
    
    <!-- Header -->
    <div class="payment-header">
        <h1><i class="fas fa-credit-card"></i> Complete Your Payment</h1>
        <p>Booking ID: #<?php echo htmlspecialchars($booking['id']); ?> | <?php echo htmlspecialchars($booking['child_name']); ?></p>
    </div>
    
    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
    <?php endif; ?>
    
    <div class="payment-layout">
        
        <!-- LEFT COLUMN: ORDER SUMMARY -->
        <div>
            
            <!-- Booking Information -->
            <div class="card">
                <h2><i class="fas fa-information-circle"></i> Booking Details</h2>
                
                <div class="booking-info-row">
                    <span class="booking-info-label">Hospital</span>
                    <span class="booking-info-value"><?php echo htmlspecialchars($booking['hospital_name']); ?></span>
                </div>
                
                <div class="booking-info-row">
                    <span class="booking-info-label">Appointment Date</span>
                    <span class="booking-info-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></span>
                </div>
                
                <div class="booking-info-row">
                    <span class="booking-info-label">Appointment Time</span>
                    <span class="booking-info-value"><?php echo date('H:i', strtotime($booking['booking_time'])); ?></span>
                </div>
                
                <div class="booking-info-row">
                    <span class="booking-info-label">Child</span>
                    <span class="booking-info-value"><?php echo htmlspecialchars($booking['child_name']); ?></span>
                </div>
            </div>
            
            <!-- Vaccinations -->
            <div class="card" style="margin-top: 20px;">
                <h2><i class="fas fa-syringe"></i> Vaccinations</h2>
                
                <?php foreach ($vaccines as $vaccine): ?>
                    <div class="vaccine-item">
                        <div class="vaccine-info">
                            <div class="vaccine-name"><?php echo htmlspecialchars($vaccine['vaccine_name']); ?></div>
                            <div class="vaccine-type">Type: <?php echo htmlspecialchars($vaccine['vaccine_type']); ?></div>
                        </div>
                        <div class="vaccine-price">PKR <?php echo number_format($vaccine['price'] * $vaccine['quantity'], 2); ?></div>
                    </div>
                <?php endforeach; ?>
                
                <div class="total-amount-box">
                    <div class="total-label">TOTAL PAYABLE</div>
                    <div class="total-value">PKR <?php echo number_format($total_amount, 2); ?></div>
                </div>
            </div>
            
        </div>
        
        <!-- RIGHT COLUMN: PAYMENT FORM -->
        <div>
            
            <div class="card">
                <h2><i class="fas fa-wallet"></i> Payment Method</h2>
                
                <form id="paymentForm" method="POST" action="payment_api.php">
                    
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="process_payment">
                    <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($booking_id); ?>">
                    <input type="hidden" name="payment_method" value="card">
                    
                    <!-- Select Payment Account -->
                    <div class="form-group">
                        <label><i class="fas fa-user-circle"></i> Payment Account</label>
                        
                        <div class="tabs">
                            <button type="button" class="tab-button active" onclick="switchTab(event, 'existing-account')">
                                Existing Account
                            </button>
                            <button type="button" class="tab-button" onclick="switchTab(event, 'new-account')">
                                Create Account
                            </button>
                        </div>
                        
                        <!-- Existing Accounts Tab -->
                        <div id="existing-account" class="tab-content active">
                            <?php if (!empty($payment_accounts)): ?>
                                <div class="account-selector">
                                    <?php foreach ($payment_accounts as $account): ?>
                                        <label class="account-option" style="flex: 1;">
                                            <input type="radio" name="payment_account_id" 
                                                   value="<?php echo htmlspecialchars($account['id']); ?>"
                                                   <?php echo $payment_accounts[0]['id'] === $account['id'] ? 'checked' : ''; ?>>
                                            <span class="account-label"><?php echo htmlspecialchars(substr($account['email'], 0, 15)); ?></span>
                                            <span class="account-detail">Balance: PKR <?php echo number_format($account['account_balance'], 2); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert info">
                                    <i class="fas fa-info-circle"></i>
                                    <span>No payment accounts found. Please create one to proceed.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- New Account Tab -->
                        <div id="new-account" class="tab-content">
                            <input type="hidden" name="create_account" value="1">
                            
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" name="account_email" 
                                       value="<?php echo htmlspecialchars($parent_info['email'] ?? ''); ?>"
                                       required>
                                <small style="color: #6b7280;">We'll send receipts to this email</small>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="tel" name="account_phone" 
                                       value="<?php echo htmlspecialchars($parent_info['phone'] ?? ''); ?>"
                                       required>
                                <small style="color: #6b7280;">For payment notifications</small>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Create Password</label>
                                <input type="password" name="account_password" 
                                       placeholder="At least 6 characters"
                                       required>
                                <small style="color: #6b7280;">Secure your payment account</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Payment Method -->
                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select name="payment_method" required>
                            <option value="card">Credit/Debit Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="wallet">Digital Wallet</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn primary">
                        <i class="fas fa-lock"></i> Proceed to Secure Payment
                    </button>
                    
                    <!-- Security Badge -->
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i>
                        Secure transaction using SSL encryption
                    </div>
                    
                </form>
            </div>
            
        </div>
        
    </div>
    
</main>

<script>
function switchTab(event, tabName) {
    event.preventDefault();
    
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

// Form validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    const accountRadios = document.querySelectorAll('input[name="payment_account_id"]');
    const createAccountInput = document.querySelector('input[name="create_account"]');
    const createAccountTab = document.getElementById('new-account');
    
    // Check if new account tab is active
    if (createAccountTab.classList.contains('active')) {
        // New account creation must have password
        const password = document.querySelector('input[name="account_password"]');
        if (!password || password.value.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters');
            return false;
        }
    } else {
        // Existing account must be selected
        const checked = Array.from(accountRadios).some(radio => radio.checked);
        if (!checked && accountRadios.length > 0) {
            e.preventDefault();
            alert('Please select a payment account');
            return false;
        }
    }
});
</script>

</body>
</html>
