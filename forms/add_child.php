<?php
session_start();
include 'connection.php';
include 'csrf.php';

// Must be logged in as a parent
if (!isset($_SESSION['parent_id'])) {
    header("Location: login.php");
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$feedback = "";

/* fetch parent info for header */
$stmt = $conn->prepare("SELECT id,name,email,phone FROM parents WHERE id=? LIMIT 1");
$stmt->bind_param("i", $parent_id);
$stmt->execute();
$parent = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_child'])) {

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $name   = trim($_POST['name']);
    $dob    = trim($_POST['date_of_birth']);
    $gender = trim($_POST['gender']);

    if ($name === "" || $dob === "" || $gender === "") {
        $feedback = "All fields are required.";
    } else {
        // Prepared statement
        $stmt = $conn->prepare("
            INSERT INTO children (parent_id, name, date_of_birth, gender)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $parent_id, $name, $dob, $gender);

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: parent.php?child_added=1");
            exit;
        } else {
            $feedback = "Error adding child: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Add Child — Medilab</title>
    <?php $css_ver = file_exists(__DIR__ . '/parent.css') ? filemtime(__DIR__ . '/parent.css') : time(); ?>
    <link rel="stylesheet" href="parent.css?v=<?php echo $css_ver; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="parent-page">

<header class="topbar">
    <div class="brand">Medilab</div>
    <div class="header-actions">
        <div class="header-profile">
            <div class="profile-avatar"><?php echo strtoupper(substr($parent['name'],0,1)); ?></div>
            <div class="profile-name"><?php echo htmlspecialchars($parent['name']); ?></div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</header>

<!-- Sidebar -->
<aside class="sidebar">
    <ul class="sidebar-menu">
        <li class="sidebar-menu-item"><a href="parent.php"><i class="fas fa-home"></i> Dashboard</a></li>
        <li class="sidebar-menu-item"><a href="add_child.php" class="active"><i class="fas fa-child"></i> Add Child</a></li>
        <li class="sidebar-menu-item"><a href="vaccines.php"><i class="fas fa-calendar"></i> Vaccination Schedule</a></li>
        <li class="sidebar-menu-item"><a href="appointment.php"><i class="fas fa-clipboard"></i> Book Appointment</a></li>
        <li class="sidebar-menu-item"><a href="generate_qr.php?parent_id=<?php echo $parent_id; ?>"><i class="fas fa-qrcode"></i> QR Codes</a></li>
        <li class="sidebar-menu-item"><a href="messages.php?parent_id=<?php echo $parent_id; ?>"><i class="fas fa-comments"></i> Messages</a></li>
    </ul>
</aside>

<main class="wrap">
    <div class="welcome-section" style="padding:20px 24px; margin-bottom:18px;">
        <h1 style="font-size:22px; margin-bottom:4px;">Add a Child</h1>
        <p style="margin:0; opacity:0.9;">Provide your child's information to keep vaccination records up to date.</p>
    </div>

    <div class="content-grid">
        <div>
            <div class="card">
                <?php if ($feedback): ?>
                    <div class="message-info"><?php echo htmlspecialchars($feedback); ?></div>
                <?php endif; ?>

                <form method="POST" class="form-group">
                    <?php echo csrf_field(); ?>

                    <div style="display:grid; grid-template-columns: 1fr 220px; gap:18px; align-items:start;">
                        <div>
                            <label>Name</label>
                            <input type="text" name="name" placeholder="Child's full name" required>

                            <label style="margin-top:12px;">Date of Birth</label>
                            <input type="date" name="date_of_birth" required>

                            <label style="margin-top:12px;">Gender</label>
                            <div style="display:flex; gap:10px; margin-top:6px;">
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="radio" name="gender" value="male" required> <i class="fas fa-mars" style="color:var(--primary-blue);"></i> Male
                                </label>
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="radio" name="gender" value="female"> <i class="fas fa-venus" style="color:var(--secondary-green);"></i> Female
                                </label>
                                <label style="display:flex; align-items:center; gap:8px;">
                                    <input type="radio" name="gender" value="other"> <i class="fas fa-genderless"></i> Other
                                </label>
                            </div>

                            <div style="margin-top:18px; display:flex; gap:12px;">
                                <button class="btn primary" name="add_child" type="submit"><i class="fas fa-plus-circle"></i> Add Child</button>
                                <a href="parent.php" class="btn secondary" style="align-self:center; display:inline-flex;"><i class="fas fa-arrow-left"></i> Back</a>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex; flex-direction:column; gap:12px; align-items:center;">
                                <div style="width:160px; height:160px; border-radius:14px; background:linear-gradient(135deg,var(--primary-blue),var(--secondary-green)); display:flex; align-items:center; justify-content:center; color:white; font-size:48px; font-weight:800; box-shadow:var(--shadow-md);">
                                    <?php echo strtoupper(substr($_SESSION['parent_name'] ?? $parent['name'],0,1)); ?>
                                </div>
                                <p style="font-size:13px; color:var(--text-muted); text-align:center;">Profile avatar generated from initials. You can update later.</p>
                                <div class="sidebar-card">
                                    <h3><i class="fas fa-info-circle"></i> Tips</h3>
                                    <ul>
                                        <li>Use full legal name for records</li>
                                        <li>Provide accurate date of birth</li>
                                        <li>Contact support if you need help</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div>
            <div class="sidebar-card">
                <h3><i class="fas fa-shield-alt"></i> Privacy</h3>
                <p>All personal data is stored securely and only used for vaccination management.</p>
                <hr style="border:none; border-top:1px solid var(--border-light); margin:12px 0;">
                <h3 style="font-size:14px; color:var(--text-dark);">Need help?</h3>
                <p style="font-size:13px; color:var(--text-muted);">Contact support or visit the documentation for guidance.</p>
            </div>
        </div>
    </div>

</main>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Medilab Medical Portal</p>
    </footer>

</body>
</html>
