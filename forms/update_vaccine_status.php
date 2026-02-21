<?php
session_start();
include 'db_connect.php';
if(!isset($_SESSION['user_id'])) exit('Unauthorized');

$booking_id = $_POST['booking_id'] ?? 0;
$status = $_POST['status'] ?? '';

if($booking_id && in_array($status,['Vaccinated','Not Vaccinated'])){
    if($status=='Vaccinated'){
        // Insert record if not exists
        $check = $conn->query("SELECT id FROM vaccination_records WHERE booking_id=$booking_id");
        if($check->num_rows==0){
            $booking = $conn->query("SELECT hospital_id, vaccine_type FROM bookings WHERE id=$booking_id")->fetch_assoc();
            $vaccine = $conn->query("SELECT id FROM vaccines WHERE hospital_id={$booking['hospital_id']} AND name='{$booking['vaccine_type']}'")->fetch_assoc();
            $admin_id = $_SESSION['user_id'];
            $conn->query("INSERT INTO vaccination_records (booking_id,vaccine_id,administered_by,administered_date)
                         VALUES ($booking_id, {$vaccine['id']}, $admin_id, CURDATE())");
        }
    } else {
        // Remove record if exists
        $conn->query("DELETE FROM vaccination_records WHERE booking_id=$booking_id");
    }
    echo 'success';
} else echo 'fail';
?>
