<?php
session_start();
include 'connection.php';
include 'csrf.php';

// REQUIRE LOGIN
if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$feedback = "";

/* --------------------------------------------------------
   HANDLE DELETE BOOKING
---------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking'])) {

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if ($booking_id > 0) {
        // Verify ownership
        $verify = $conn->prepare("SELECT id FROM bookings WHERE id=? AND parent_id=? LIMIT 1");
        $verify->bind_param("ii", $booking_id, $parent_id);
        $verify->execute();
        $verify_result = $verify->get_result();

        if ($verify_result->num_rows > 0) {
            $stmt = $conn->prepare("DELETE FROM bookings WHERE id=? AND parent_id=?");
            $stmt->bind_param("ii", $booking_id, $parent_id);

            if ($stmt->execute()) {
                error_log("parent.php: Booking deleted successfully. booking_id=$booking_id, parent_id=$parent_id");
                $feedback = "✓ Booking deleted successfully!";
            } else {
                error_log("parent.php: Booking DELETE failed: " . $stmt->error);
                $feedback = "❌ Error deleting booking: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $feedback = "❌ Booking not found or you don't have permission to delete it.";
        }
        $verify->close();
    } else {
        $feedback = "❌ Invalid booking ID.";
    }
}

/* --------------------------------------------------------
   HANDLE DELETE CHILD
---------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_child'])) {

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $child_id = (int)($_POST['child_id'] ?? 0);

    if ($child_id > 0) {
        // Verify ownership
        $verify = $conn->prepare("SELECT id FROM children WHERE id=? AND parent_id=? LIMIT 1");
        $verify->bind_param("ii", $child_id, $parent_id);
        $verify->execute();
        $verify_result = $verify->get_result();

        if ($verify_result->num_rows > 0) {
            $stmt = $conn->prepare("DELETE FROM children WHERE id=? AND parent_id=?");
            $stmt->bind_param("ii", $child_id, $parent_id);

            if ($stmt->execute()) {
                error_log("parent.php: Child deleted successfully. child_id=$child_id, parent_id=$parent_id");
                $feedback = "✓ Child record deleted successfully!";
                // Redirect to refresh the page
                header("Location: parent.php");
                exit;
            } else {
                error_log("parent.php: Child DELETE failed: " . $stmt->error);
                $feedback = "❌ Error deleting child: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $feedback = "❌ Child not found or you don't have permission to delete it.";
        }
        $verify->close();
    } else {
        $feedback = "❌ Invalid child ID.";
    }
}

/* --------------------------------------------------------
   HANDLE QUICK BOOKING FORM
---------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_booking'])) {

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $child_id     = (int)($_POST['child_id'] ?? 0);
    $hospital_id  = (int)($_POST['hospital_id'] ?? 0);
    $vaccine_type = trim($_POST['vaccine_type'] ?? '');
    $booking_date = trim($_POST['booking_date'] ?? '');
    $booking_time = trim($_POST['booking_time'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    if (!$child_id || !$hospital_id || !$vaccine_type || !$booking_date || !$booking_time) {
        $feedback = "Please complete all fields.";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO bookings 
            (parent_id, hospital_id, child_id, vaccine_type, booking_date, booking_time, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->bind_param(
            "iiissss",
            $parent_id, $hospital_id, $child_id, $vaccine_type,
            $booking_date, $booking_time, $notes
        );

        if ($stmt->execute()) {
            error_log("parent.php: Booking inserted successfully. parent_id=$parent_id, hospital_id=$hospital_id, child_id=$child_id, vaccine_type=$vaccine_type, booking_date=$booking_date, booking_time=$booking_time");
            $feedback = "✓ Booking request sent!";
        } else {
            error_log("parent.php: Booking INSERT failed: " . $stmt->error);
            $feedback = "❌ Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

/* --------------------------------------------------------
   PARENT DATA
---------------------------------------------------------*/
$stmt = $conn->prepare("SELECT id,name,email,phone FROM parents WHERE id=? LIMIT 1");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* --------------------------------------------------------
   CHILDREN LIST
---------------------------------------------------------*/
$stmt = $conn->prepare("SELECT id,name,date_of_birth,gender FROM children WHERE parent_id=? ORDER BY id DESC");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$children = $stmt->get_result();
$stmt->close();

// detect if vaccines.price column exists in the current database
$has_price = false;
$col_check = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vaccines' AND COLUMN_NAME = 'price'");
if ($col_check) {
    if ($col_check->execute()) {
        $res = $col_check->get_result();
        $has_price = (int)($res->fetch_assoc()['cnt'] ?? 0) > 0;
    } else {
        error_log("parent.php: failed to execute column-check query: " . $col_check->error);
    }
    $col_check->close();
} else {
    error_log("parent.php: failed to prepare column-check query: " . $conn->error);
}

/* --------------------------------------------------------
   BOOKINGS LIST
---------------------------------------------------------*/
$stmt = $conn->prepare("
    SELECT b.id,b.vaccine_type,b.booking_date,b.booking_time,b.status,
           c.name AS child_name, h.name AS hospital_name
    FROM bookings b
    JOIN children c ON b.child_id=c.id
    JOIN hospitals h ON b.hospital_id=h.id
    WHERE b.parent_id = ?
    ORDER BY b.created_at DESC
");
$stmt->bind_param("i",$parent_id);
$stmt->execute();
$bookings = $stmt->get_result();
$stmt->close();

/* --------------------------------------------------------
   DASHBOARD COUNTS
---------------------------------------------------------*/

// total children
$stmt = $conn->prepare("SELECT COUNT(*) AS x FROM children WHERE parent_id=?");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$total_children = $stmt->get_result()->fetch_assoc()['x'];
$stmt->close();

// total bookings
$stmt = $conn->prepare("SELECT COUNT(*) AS x FROM bookings WHERE parent_id=?");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$total_bookings = $stmt->get_result()->fetch_assoc()['x'];
$stmt->close();

// completed bookings
$stmt = $conn->prepare("SELECT COUNT(*) AS x FROM bookings WHERE parent_id=? AND status='completed'");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$completed = $stmt->get_result()->fetch_assoc()['x'];
$stmt->close();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Parent Dashboard - Medical Portal</title>
    <?php $css_ver = file_exists(__DIR__ . '/parent.css') ? filemtime(__DIR__ . '/parent.css') : time(); ?>
    <link rel="stylesheet" href="parent.css?v=<?php echo $css_ver; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="parent-page">

<!-- ========== HEADER ========== -->
<header class="topbar">
    <div class="brand">Medilab</div>
    <div class="header-actions">
        <div class="header-profile" onclick="openProfileModal('parent')" style="cursor: pointer;">
            <div class="profile-avatar" title="Click to edit profile"><?php echo strtoupper(substr($parent['name'], 0, 1)); ?></div>
            <div class="profile-name"><?php echo htmlspecialchars($parent['name']); ?></div>
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
            <a href="parent.php" class="active">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="add_child.php">
                <i class="fas fa-child"></i> Add Child
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="vaccines.php">
                <i class="fas fa-calendar"></i> Vaccination Schedule
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="appointment.php">
                <i class="fas fa-clipboard"></i> Book Appointment
            </a>
        </li>
        <li class="sidebar-menu-item">
            <a href="parent_payments.php">
                <i class="fas fa-credit-card"></i> Payments & Reviews
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

    <!-- WELCOME SECTION -->
    <section class="welcome-section">
        <h1>Welcome back, <?php echo htmlspecialchars($parent['name']); ?>! 👋</h1>
        <p>Manage your children's vaccination records and book appointments with ease.</p>
    </section>

    <!-- STATS GRID -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">👶</div>
            <div class="stat-label">Total Children</div>
            <div class="stat-value"><?php echo $total_children; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">📅</div>
            <div class="stat-label">Total Bookings</div>
            <div class="stat-value"><?php echo $total_bookings; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber">✅</div>
            <div class="stat-label">Completed</div>
            <div class="stat-value"><?php echo $completed; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">💙</div>
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?php echo $total_bookings - $completed; ?></div>
        </div>
    </div>

    <!-- MAIN CONTENT GRID -->
    <div class="content-grid">

        <!-- LEFT COLUMN: Children & Bookings -->
        <div>

            <!-- YOUR CHILDREN CARD -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-children card-title-icon"></i> Your Children
                    </h2>
                    <a href="add_child.php" class="btn primary small">
                        <i class="fas fa-plus"></i> Add Child
                    </a>
                </div>

                <?php if ($children->num_rows == 0): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">👨‍👩‍👧</div>
                        <p>No children added yet. Click "Add Child" to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="children-list">
                        <?php while($ch = $children->fetch_assoc()): 
                            $cid = (int)$ch['id'];
                            
                            // Calculate total cost for this child (only if vaccines.price exists)
                            if ($has_price) {
                                $tot_sql = "
                                    SELECT IFNULL(SUM(v.price),0) AS total
                                    FROM bookings b
                                    LEFT JOIN vaccines v ON v.type=b.vaccine_type
                                    WHERE b.child_id=?
                                ";
                                $tp_stmt = $conn->prepare($tot_sql);
                                if ($tp_stmt) {
                                    $tp_stmt->bind_param("i", $cid);
                                    if ($tp_stmt->execute()) {
                                        $tr = $tp_stmt->get_result();
                                        $total_price = $tr ? ($tr->fetch_assoc()['total'] ?? 0) : 0;
                                    } else {
                                        error_log("parent.php: failed to execute total price query: " . $tp_stmt->error . " | SQL: " . $tot_sql);
                                        $total_price = 0;
                                    }
                                    $tp_stmt->close();
                                } else {
                                    error_log("parent.php: failed to prepare total price query: " . $conn->error . " | SQL: " . $tot_sql);
                                    $total_price = 0;
                                }
                            } else {
                                // vaccines.price not available in schema — fall back
                                $total_price = 0;
                            }
                        ?>
                        <div class="child-item">
                            <div class="child-avatar"><?php echo strtoupper(substr($ch['name'], 0, 1)); ?></div>
                            <div class="child-info">
                                <div class="child-name"><?php echo htmlspecialchars($ch['name']); ?></div>
                                <div class="child-detail">
                                    <?php echo htmlspecialchars($ch['gender']); ?> • DOB: <?php echo htmlspecialchars($ch['date_of_birth']); ?>
                                </div>
                            </div>
                            <div class="child-price">PKR <?php echo number_format($total_price, 2); ?></div>
                            <div class="child-actions">
                                <a href="messages.php?child_id=<?php echo $cid; ?>" class="btn-icon" title="Messages">
                                    <i class="fas fa-comments"></i>
                                </a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this child record? This will also delete all associated bookings.');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="child_id" value="<?php echo $cid; ?>">
                                    <button type="submit" name="delete_child" class="btn-icon delete" title="Delete Child" style="background: #ef4444; color: white; border: none; cursor: pointer;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- YOUR BOOKINGS CARD -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-calendar-check card-title-icon"></i> Your Bookings
                    </h2>
                </div>

                <?php if ($bookings->num_rows == 0): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📋</div>
                        <p>No bookings yet. Create your first booking using the form on the right.</p>
                    </div>
                <?php else: ?>
                    <table class="bookings-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-child"></i> Child</th>
                                <th><i class="fas fa-hospital"></i> Hospital</th>
                                <th><i class="fas fa-syringe"></i> Vaccine</th>
                                <th><i class="fas fa-calendar"></i> Date & Time</th>
                                <th><i class="fas fa-credit-card"></i> Payment</th>
                                <th><i class="fas fa-cogs"></i> Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($b = $bookings->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($b['child_name']); ?></td>
                                <td><?php echo htmlspecialchars($b['hospital_name']); ?></td>
                                <td><?php echo htmlspecialchars($b['vaccine_type']); ?></td>
                                <td><?php echo htmlspecialchars($b['booking_date'] . ' ' . $b['booking_time']); ?></td>
                                <td>
                                    <?php 
                                    $payment_status = htmlspecialchars($b['payment_status'] ?? 'unpaid');
                                    $status_color = $payment_status === 'paid' ? 'green' : ($payment_status === 'pending' ? 'amber' : 'red');
                                    ?>
                                    <span class="badge <?php echo $status_color; ?>" style="font-size: 12px; padding: 4px 8px;">
                                        <?php echo strtoupper($payment_status); ?>
                                    </span>
                                </td>
                                <td style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <?php if ($payment_status === 'unpaid' || $payment_status === 'pending'): ?>
                                        <a href="payment.php?booking_id=<?php echo $b['id']; ?>" style="background: #3b82f6; color: white; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; white-space: nowrap; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);">
                                            <i class="fas fa-credit-card" style="font-size: 16px;"></i>
                                            <span style="display: inline;">PAY</span>
                                        </a>
                                    <?php elseif ($payment_status === 'paid'): ?>
                                        <a href="parent_payments.php" style="background: #10b981; color: white; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; white-space: nowrap; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
                                            <i class="fas fa-star" style="font-size: 16px;"></i>
                                            <span style="display: inline;">Review</span>
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this booking?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                        <button type="submit" name="delete_booking" style="background: #ef4444; color: white; padding: 10px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);" title="Delete Booking">
                                            <i class="fas fa-trash" style="font-size: 14px;"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Quick Booking Form -->
        <div>
            <div class="sidebar-card">
                <h3>
                    <i class="fas fa-plus-circle"></i> Quick Booking
                </h3>
                <p>Book a vaccination appointment in just a few clicks</p>

                <?php if ($feedback): ?>
                    <div class="message-info">
                        ✓ <?php echo htmlspecialchars($feedback); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="form-group">
                    <?php echo csrf_field(); ?>

                    <label>
                        <i class="fas fa-child"></i> Select Child
                    </label>
                    <select name="child_id" required>
                        <option value="">-- Choose a child --</option>
                        <?php
                        $c = $conn->prepare("SELECT id,name FROM children WHERE parent_id=? ORDER BY name");
                        $c->bind_param("i", $parent_id);
                        $c->execute();
                        $cr = $c->get_result();
                        while($row = $cr->fetch_assoc()):
                        ?>
                            <option value="<?= $row['id']; ?>">
                                <?= htmlspecialchars($row['name']); ?>
                            </option>
                        <?php endwhile; $c->close(); ?>
                    </select>

                    <label>
                        <i class="fas fa-hospital"></i> Hospital
                    </label>
                    <select name="hospital_id" required>
                        <option value="">-- Choose a hospital --</option>
                        <?php
                        $h = $conn->query("SELECT id,name FROM hospitals ORDER BY name");
                        while($hr = $h->fetch_assoc()):
                        ?>
                            <option value="<?= $hr['id']; ?>">
                                <?= htmlspecialchars($hr['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                    <label>
                        <i class="fas fa-syringe"></i> Vaccine Type
                    </label>
                    <select name="vaccine_type" required>
                        <option value="">-- Select vaccine --</option>
                        <option>BCG</option>
                        <option>HepB</option>
                        <option>Polio</option>
                        <option>DPT</option>
                        <option>Measles</option>
                    </select>

                    <label>
                        <i class="fas fa-calendar"></i> Booking Date
                    </label>
                    <input type="date" name="booking_date" required>

                    <label>
                        <i class="fas fa-clock"></i> Booking Time
                    </label>
                    <input type="time" name="booking_time" required>

                    <label>
                        <i class="fas fa-note-sticky"></i> Additional Notes
                    </label>
                    <textarea name="notes" placeholder="Any special requests or allergies..."></textarea>

                    <button class="btn primary" name="add_booking" type="submit">
                        <i class="fas fa-check-circle"></i> Request Booking
                    </button>
                </form>
            </div>
        </div>
    </div></main>

<!-- FOOTER -->
<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal. All rights reserved. | <a href="/Medilab/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none;">Visit Website</a></p>
</footer>

<!-- Profile Modal Component -->
<?php include 'profile_modal.php'; ?>

</body>
</html>
                <h4><i class="fas fa-qrcode" style="color: var(--primary-blue);"></i> QR Code Tools</h4>
                <p class="muted">Generate and download QR codes for quick health record sharing.</p>
                <a class="btn primary" href="generate_qr.php?parent_id=<?php echo $parent_id; ?>" style="width: 100%; text-align: center;">
                    <i class="fas fa-download"></i> Manage QR Codes
                </a>
            </div>

            <!-- MESSAGES CARD -->
            <div class="card">
                <h4><i class="fas fa-message" style="color: var(--secondary-green);"></i> Messages</h4>
                <p class="muted">Real-time communication with healthcare providers. Messages auto-refresh.</p>
                <a class="btn ghost" href="messages.php?parent_id=<?php echo $parent_id; ?>" style="width: 100%; text-align: center;">
                    <i class="fas fa-comments"></i> Open Messages
                </a>
            </div>

            <!-- HELP CARD -->
            <div class="card">
                <h4><i class="fas fa-lightbulb" style="color: #fbbf24;"></i> Quick Tips</h4>
                <ul style="padding-left: 20px; color: var(--text-muted); font-size: 13px;">
                    <li style="margin-bottom: 8px;">Keep vaccination records up-to-date</li>
                    <label>
                        <i class="fas fa-clock"></i> Booking Time
                    </label>
                    <input type="time" name="booking_time" required>

                    <label>
                        <i class="fas fa-note-sticky"></i> Additional Notes
                    </label>
                    <textarea name="notes" placeholder="Any special requests or allergies..."></textarea>

                    <button class="btn primary" name="add_booking" type="submit">
                        <i class="fas fa-check-circle"></i> Request Booking
                    </button>
                </form>
            </div>
        </div>
    </div>

</main>

<!-- FOOTER -->
<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal. All rights reserved. | <a href="/Medilab/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none;">Visit Website</a></p>
</footer>

</body>
</html>


