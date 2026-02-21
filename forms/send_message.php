<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['parent_id'])) exit;
$parent_id = (int)$_SESSION['parent_id'];
$child_id  = (int)($_POST['child_id'] ?? 0);
$msg       = trim($_POST['message'] ?? '');

if($msg && $child_id){
    $stmt = $conn->prepare("INSERT INTO messages (sender_type,sender_id,receiver_id,child_id,message) VALUES ('parent',?,?,?,?)");
    $stmt->bind_param("iiis",$parent_id,$parent_id,$child_id,$msg);
    $stmt->execute();
    $stmt->close();
}
