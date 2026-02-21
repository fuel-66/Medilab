<?php
session_start();
include 'connection.php';

// Must be logged in as a hospital
if (!isset($_SESSION['hospital_id'])) {
    echo "Access denied";
    exit;
}

$payload = $_GET['child_id'] ?? $_GET['parent_id'] ?? $_GET['booking_id'] ?? null;

if (!$payload) {
    echo "Invalid QR code.";
    exit;
}

// Build dynamic WHERE conditions
$where = [];
$params = [];
$types = "";

if (isset($_GET['child_id'])) {
    $where[] = "c.id = ?";
    $params[] = (int)$_GET['child_id'];
    $types .= "i";
}
if (isset($_GET['parent_id'])) {
    $where[] = "p.id = ?";
    $params[] = (int)$_GET['parent_id'];
    $types .= "i";
}
if (isset($_GET['booking_id'])) {
    $where[] = "b.id = ?";
    $params[] = (int)$_GET['booking_id'];
    $types .= "i";
}

$query = "
    SELECT c.*, p.name AS parent_name, p.email AS parent_email, p.phone AS parent_phone
    FROM children c
    JOIN parents p ON c.parent_id = p.id
    LEFT JOIN bookings b ON b.child_id = c.id
    WHERE " . implode(" AND ", $where) . "
    LIMIT 1
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    echo "No matching record found.";
    exit;
}
?>
<!doctype html>
<html>
<head><title>QR Verification</title></head>
<body style="font-family:Arial;padding:20px">
<h2>QR Code Verification</h2>

<p><strong>Child:</strong> <?= htmlspecialchars($data['name']) ?></p>
<p><strong>Date of Birth:</strong> <?= htmlspecialchars($data['date_of_birth']) ?></p>
<p><strong>Gender:</strong> <?= htmlspecialchars($data['gender']) ?></p>

<h3>Parent Info</h3>
<p><strong>Name:</strong> <?= htmlspecialchars($data['parent_name']) ?></p>
<p><strong>Email:</strong> <?= htmlspecialchars($data['parent_email']) ?></p>
<p><strong>Phone:</strong> <?= htmlspecialchars($data['parent_phone']) ?></p>

</body>
</html>
