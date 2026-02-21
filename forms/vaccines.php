<?php
session_start();
// Use the project's mysqli connection wrapper
// `db_connect.php` was missing in this codebase; `connection.php` provides `$conn`.
include_once __DIR__ . '/connection.php';

if (!isset($conn) || !$conn) {
    error_log("vaccines.php: database connection (
        connection.php) did not provide \$conn");
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database connection not available. Please ensure forms/connection.php is present and MySQL is running.";
    exit;
}

// Check user login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'admin';

// Fetch bookings & vaccination data
if ($user_role === 'admin') {
    $sql = "SELECT b.id as booking_id, c.name as child_name, v.name as vaccine_name,
            IF(vr.id IS NULL,'Not Vaccinated','Vaccinated') as status,
            p.name as parent_name, h.name as hospital_name
            FROM bookings b
            JOIN children c ON b.child_id=c.id
            JOIN parents p ON b.parent_id=p.id
            JOIN hospitals h ON b.hospital_id=h.id
            JOIN vaccines v ON v.name=b.vaccine_type AND v.hospital_id=b.hospital_id
            LEFT JOIN vaccination_records vr ON vr.booking_id=b.id";
} elseif ($user_role === 'hospital') {
    $sql = "SELECT b.id as booking_id, c.name as child_name, v.name as vaccine_name,
            IF(vr.id IS NULL,'Not Vaccinated','Vaccinated') as status,
            p.name as parent_name, h.name as hospital_name
            FROM bookings b
            JOIN children c ON b.child_id=c.id
            JOIN parents p ON b.parent_id=p.id
            JOIN hospitals h ON b.hospital_id=h.id
            JOIN vaccines v ON v.name=b.vaccine_type AND v.hospital_id=b.hospital_id
            LEFT JOIN vaccination_records vr ON vr.booking_id=b.id
            WHERE h.id=?";
} else { // parent
    $sql = "SELECT b.id as booking_id, c.name as child_name, v.name as vaccine_name,
            IF(vr.id IS NULL,'Not Vaccinated','Vaccinated') as status,
            p.name as parent_name, h.name as hospital_name
            FROM bookings b
            JOIN children c ON b.child_id=c.id
            JOIN parents p ON b.parent_id=p.id
            JOIN hospitals h ON b.hospital_id=h.id
            JOIN vaccines v ON v.name=b.vaccine_type AND v.hospital_id=b.hospital_id
            LEFT JOIN vaccination_records vr ON vr.booking_id=b.id
            WHERE p.id=?";
}

// Prepare and execute
$stmt = $conn->prepare($sql);
if ($user_role === 'hospital') $stmt->bind_param("i", $user_id);
if ($user_role === 'parent') $stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$vaccine_data = $result->fetch_all(MYSQLI_ASSOC);

// Stats
$total = count($vaccine_data);
$vaccinated = count(array_filter($vaccine_data, fn($v)=>$v['status']=='Vaccinated'));
$not_vaccinated = $total - $vaccinated;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vaccines Dashboard — Medilab</title>
<?php $css_ver = file_exists(__DIR__ . '/parent.css') ? filemtime(__DIR__ . '/parent.css') : time(); ?>
<link rel="stylesheet" href="parent.css?v=<?php echo $css_ver; ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="parent-page">

<header class="topbar">
    <div class="brand">Medilab</div>
    <div class="header-actions">
        <div class="header-profile">
            <div class="profile-avatar"><?php echo strtoupper(substr($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'U'),0,1)); ?></div>
            <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ($_SESSION['name'] ?? 'User')); ?></div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</header>

<aside class="sidebar">
    <ul class="sidebar-menu">
        <li class="sidebar-menu-item"><a href="parent.php"><i class="fas fa-home"></i> Dashboard</a></li>
        <li class="sidebar-menu-item"><a href="add_child.php"><i class="fas fa-child"></i> Add Child</a></li>
        <li class="sidebar-menu-item"><a href="vaccines.php" class="active"><i class="fas fa-syringe"></i> Vaccination Schedule</a></li>
        <li class="sidebar-menu-item"><a href="appointment.php"><i class="fas fa-clipboard"></i> Book Appointment</a></li>
        <li class="sidebar-menu-item"><a href="generate_qr.php"><i class="fas fa-qrcode"></i> QR Codes</a></li>
        <li class="sidebar-menu-item"><a href="messages.php"><i class="fas fa-comments"></i> Messages</a></li>
    </ul>
</aside>

<main class="wrap">
    <section class="welcome-section">
        <h1>Vaccines Dashboard</h1>
        <p style="margin:0; opacity:0.9;">Overview of vaccination records and quick actions.</p>
    </section>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">💉</div>
            <div class="stat-label">Total Records</div>
            <div class="stat-value"><?php echo $total; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div class="stat-label">Vaccinated</div>
            <div class="stat-value"><?php echo $vaccinated; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber">⚠️</div>
            <div class="stat-label">Not Vaccinated</div>
            <div class="stat-value"><?php echo $not_vaccinated; ?></div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title"><i class="fas fa-list card-title-icon"></i> Vaccination Records</h2>
        <p style="color:var(--text-muted); margin-top:6px;">Click a card to toggle status, or use the table below for bulk actions.</p>

        <div class="vaccine-grid" style="margin-top:18px;">
            <?php foreach($vaccine_data as $v):
                $statusClass = $v['status']=='Vaccinated' ? 'vaccinated' : 'not-vaccinated';
            ?>
            <div class="vaccine-card" data-id="<?php echo $v['booking_id']; ?>" onclick="toggleStatusCard(this)">
                <div class="vaccine-top">
                    <div class="vaccine-icon">👶</div>
                    <div>
                        <div style="font-weight:700; color:var(--text-dark);"><?php echo htmlspecialchars($v['child_name']); ?></div>
                        <div style="font-size:13px; color:var(--text-muted);"><?php echo htmlspecialchars($v['vaccine_name']); ?> • <?php echo htmlspecialchars($v['hospital_name']); ?></div>
                    </div>
                </div>
                <div class="vaccine-bottom">
                    <div class="vaccine-parent">Parent: <?php echo htmlspecialchars($v['parent_name']); ?></div>
                    <div class="vaccine-status <?php echo $statusClass; ?>"><?php echo $v['status']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <hr style="margin:22px 0; border:none; border-top:1px solid var(--border-light);">

        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Child</th>
                    <th>Vaccine</th>
                    <th>Parent</th>
                    <th>Hospital</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($vaccine_data as $v): ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['child_name']); ?></td>
                    <td><?php echo htmlspecialchars($v['vaccine_name']); ?></td>
                    <td><?php echo htmlspecialchars($v['parent_name']); ?></td>
                    <td><?php echo htmlspecialchars($v['hospital_name']); ?></td>
                    <td><span class="badge <?php echo strtolower(str_replace(' ','-',$v['status'])); ?>"><?php echo $v['status']; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> Medilab</p>
    </footer>

<script>
function toggleStatusCard(el){
    const id = el.getAttribute('data-id');
    const statusEl = el.querySelector('.vaccine-status');
    const current = statusEl.textContent.trim();
    const newStatus = current === 'Vaccinated' ? 'Not Vaccinated' : 'Vaccinated';

    fetch('update_vaccine_status.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'booking_id='+encodeURIComponent(id)+'&status='+encodeURIComponent(newStatus)
    }).then(r=>r.text()).then(txt=>{
        if(txt==='success'){
            statusEl.textContent = newStatus;
            statusEl.classList.toggle('vaccinated');
            statusEl.classList.toggle('not-vaccinated');
            el.classList.add('pulse');
            setTimeout(()=>el.classList.remove('pulse'),600);
        } else {
            alert('Update failed');
        }
    });
}
</script>
</body>
</html>

