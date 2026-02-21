<?php
/**
 * API for fetching bookings for a selected hospital (used in parent_payments.php)
 */

session_start();
include 'connection.php';

if (!isset($_SESSION['parent_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$parent_id = (int)$_SESSION['parent_id'];
$hospital_id = (int)($_POST['hospital_id'] ?? 0);

if (!$hospital_id) {
    echo json_encode(['bookings' => []]);
    exit;
}

// Fetch bookings for this hospital
$query = "
    SELECT 
        b.id,
        b.booking_date,
        GROUP_CONCAT(v.name SEPARATOR ', ') as vaccines
    FROM bookings b
    JOIN booking_vaccinations bv ON b.id = bv.booking_id
    JOIN vaccines v ON bv.vaccine_id = v.id
    WHERE b.parent_id = ? AND b.hospital_id = ?
    GROUP BY b.id
    ORDER BY b.booking_date DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $parent_id, $hospital_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];
while ($row = $result->fetch_assoc()) {
    $bookings[] = [
        'id' => $row['id'],
        'date' => date('d M Y', strtotime($row['booking_date'])),
        'vaccines' => $row['vaccines']
    ];
}
$stmt->close();

echo json_encode(['bookings' => $bookings]);
?>
