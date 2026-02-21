<?php
session_start();
include 'connection.php';
include 'csrf.php';

if (!isset($_SESSION['hospital_id'])) {
    header("Location: login.php");
    exit;
}

$hospital_id = (int)$_SESSION['hospital_id'];
$feedback = "";

/* ---------------------------------------------------------
   HANDLE BOOKING APPROVE / COMPLETE
--------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if (isset($_POST['approve_booking'])) {
        $stmt = $conn->prepare("UPDATE bookings SET status='approved' WHERE id=? AND hospital_id=?");
        $stmt->bind_param("ii", $booking_id, $hospital_id);
        $stmt->execute();
        $stmt->close();
        $feedback = "Booking approved successfully.";
    }

    if (isset($_POST['complete_booking'])) {
        $stmt = $conn->prepare("UPDATE bookings SET status='completed' WHERE id=? AND hospital_id=?");
        $stmt->bind_param("ii", $booking_id, $hospital_id);
        $stmt->execute();
        $stmt->close();
        $feedback = "Vaccination marked as completed.";
    }
}

/* ---------------------------------------------------------
   FETCH HOSPITAL DATA
--------------------------------------------------------- */
$stmt = $conn->prepare("SELECT id,name,email,phone,address FROM hospitals WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$hospital = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ---------------------------------------------------------
   FETCH PENDING BOOKINGS
--------------------------------------------------------- */
$stmt = $conn->prepare("
        SELECT b.id,b.vaccine_type,b.booking_date,b.booking_time,b.status,b.payment_status,
          p.id AS parent_id, p.name AS parent_name,
          c.id AS child_id, c.name AS child_name, c.gender,
          c.date_of_birth
    FROM bookings b
    JOIN parents p ON b.parent_id=p.id
    JOIN children c ON b.child_id=c.id
    WHERE b.hospital_id = ?
    ORDER BY b.created_at DESC
");
$stmt->bind_param("i", $hospital_id);
$stmt->execute();
$bookings = $stmt->get_result();
error_log("hospital.php: Bookings query returned " . $bookings->num_rows . " rows for hospital_id=$hospital_id");
$stmt->close();

/* ---------------------------------------------------------
   TOTAL COUNTS
--------------------------------------------------------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE hospital_id=?");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$total_bookings = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE hospital_id=? AND status='pending'");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE hospital_id=? AND status='completed'");
$stmt->bind_param("i",$hospital_id);
$stmt->execute();
$completed = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// unread notifications count
$nstmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_type='hospital' AND user_id=? AND is_read=0");
$nstmt->bind_param("i", $hospital_id);
$nstmt->execute();
$unread_notifications = $nstmt->get_result()->fetch_assoc()['cnt'];
$nstmt->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hospital Dashboard — Medical</title>
<?php $css_ver = file_exists(__DIR__ . '/hospital.css') ? filemtime(__DIR__ . '/parent.css') : time(); ?>
<link rel="stylesheet" href="parent.css?v=<?php echo $css_ver; ?>">
<link rel="stylesheet" href="hospital.css?v=<?php echo file_exists(__DIR__ . '/hospital.css') ? filemtime(__DIR__ . '/hospital.css') : time(); ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="parent-page">

<!-- ========== HEADER ========== -->
<header class="topbar">
    <div class="brand">Medilab</div>
    <div class="header-actions">
        <div class="header-profile" onclick="openProfileModal('hospital')" style="cursor: pointer;">
            <div class="profile-avatar" title="Click to edit profile"><?php echo strtoupper(substr($hospital['name'], 0, 1)); ?></div>
            <div class="profile-name"><?php echo htmlspecialchars($hospital['name']); ?></div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</header>

<!-- ========== LEFT SIDEBAR ========== -->
<aside class="sidebar">
    <ul class="sidebar-menu">
        <li class="sidebar-menu-item">
            <a href="hospital.php" class="active">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="hospital_payments.php">
                <i class="fas fa-chart-line"></i> Payments & QR Verify
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="messages.php">
                <i class="fas fa-comments"></i> Messages
            </a>
        </li>

    </ul>
</aside>

<main class="wrap">

    <!-- WELCOME / STATS -->
    <section class="welcome-section">
        <h1>Welcome, <?php echo htmlspecialchars($hospital['name']); ?>!</h1>
        <p class="muted">Manage vaccination bookings and parent communication.</p>
    </section>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">📅</div>
            <div class="stat-label">Total Bookings</div>
            <div class="stat-value"><?php echo $total_bookings; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber">⌛</div>
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo $pending; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?php echo $completed; ?></div>
        </div>
    </div>

    <?php if ($feedback): ?>
        <div class="message-info"><?php echo htmlspecialchars($feedback); ?></div>
    <?php endif; ?>

    <div class="content-grid">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-calendar-check card-title-icon"></i> Bookings</h2>
                </div>

                <?php if ($bookings->num_rows == 0): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p class="muted">No bookings yet.</p>
                    </div>

                <?php else: ?>
                    <table class="bookings-table">
                        <thead>
                            <tr>
                                <th>Child</th>
                                <th>Parent</th>
                                <th>Vaccine</th>
                                <th>Date & Time</th>
                                <th><i class="fas fa-credit-card"></i> Payment</th>
                                <th>Status / Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while($bk = $bookings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($bk['child_name']); ?></td>
                                <td><?php echo htmlspecialchars($bk['parent_name']); ?></td>
                                <td><?php echo htmlspecialchars($bk['vaccine_type']); ?></td>
                                <td><?php echo htmlspecialchars($bk['booking_date'] . ' ' . $bk['booking_time']); ?></td>
                                <td>
                                    <?php 
                                    $payment_status = htmlspecialchars($bk['payment_status'] ?? 'unpaid');
                                    $payment_color = $payment_status === 'paid' ? 'green' : ($payment_status === 'pending' ? 'amber' : 'red');
                                    ?>
                                    <span class="badge <?php echo $payment_color; ?>" style="font-size: 12px; padding: 4px 8px;">
                                        <i class="fas fa-credit-card" style="margin-right: 4px;"></i><?php echo strtoupper($payment_status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <span class="badge <?php echo htmlspecialchars($bk['status']); ?>"><?php echo htmlspecialchars(ucfirst($bk['status'])); ?></span>
                                        <form method="POST" style="display:inline-flex;gap:8px;margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="booking_id" value="<?php echo $bk['id']; ?>">
                                            <?php if ($bk['status'] === 'pending'): ?>
                                                <button class="btn primary" name="approve_booking">Approve</button>
                                            <?php endif; ?>
                                            <?php if ($bk['status'] === 'approved'): ?>
                                                <button class="btn" name="complete_booking">Complete</button>
                                            <?php endif; ?>
                                            <a class="btn ghost" href="messages.php?parent_id=<?php echo $bk['parent_id']; ?>">Chat</a>
                                            <a class="btn" href="hospital_payments.php">Verify</a>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
            <div class="sidebar-card">
                <h3><i class="fas fa-credit-card"></i> Revenue & Verification</h3>
                <p class="muted">View payments and verify QR codes.</p>
                <a class="btn primary" href="hospital_payments.php">Dashboard</a>
            </div>

            <div class="sidebar-card">
                <h3><i class="fas fa-comments"></i> Messages</h3>
                <p class="muted">Chat with parents.</p>
                <a class="btn ghost" href="messages.php?hospital_id=<?php echo $hospital_id; ?>">Open Messages</a>
            </div>
        </div>
    </div>

</main>

<!-- FOOTER -->
<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal.</p>
</footer>

<!-- Profile Modal Component -->
<?php include 'profile_modal.php'; ?>

</body>
</html>
