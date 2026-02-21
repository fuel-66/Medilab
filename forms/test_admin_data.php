<?php
include 'connection.php';

echo "<h2>Database Diagnostics</h2>";

// Check payments table
echo "<h3>Payments Table Count:</h3>";
$result = $conn->query("SELECT COUNT(*) as count FROM payments");
$row = $result->fetch_assoc();
echo "Total payments: " . $row['count'] . "<br>";

// Check parents table
echo "<h3>Parents Table Count:</h3>";
$result = $conn->query("SELECT COUNT(*) as count FROM parents");
$row = $result->fetch_assoc();
echo "Total parents: " . $row['count'] . "<br>";

// Check hospitals table
echo "<h3>Hospitals Table Count:</h3>";
$result = $conn->query("SELECT COUNT(*) as count FROM hospitals");
$row = $result->fetch_assoc();
echo "Total hospitals: " . $row['count'] . "<br>";

// Check bookings table
echo "<h3>Bookings Table Count:</h3>";
$result = $conn->query("SELECT COUNT(*) as count FROM bookings");
$row = $result->fetch_assoc();
echo "Total bookings: " . $row['count'] . "<br>";

// Check children table
echo "<h3>Children Table Count:</h3>";
$result = $conn->query("SELECT COUNT(*) as count FROM children");
$row = $result->fetch_assoc();
echo "Total children: " . $row['count'] . "<br>";

// Sample payment
echo "<h3>Sample Payment:</h3>";
$result = $conn->query("SELECT * FROM payments LIMIT 1");
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<pre>";
    print_r($row);
    echo "</pre>";
} else {
    echo "No payments in database";
}

?>
