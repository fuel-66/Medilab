<?php
session_start();
include 'connection.php';

$me_type = isset($_SESSION['parent_id'])?'parent':'hospital';
$me_id   = $me_type==='parent'?$_SESSION['parent_id']:$_SESSION['hospital_id'];

$msg  = trim($_POST['msg']);
$type = $_POST['type'];
$id   = (int)$_POST['id'];

$stmt = $conn->prepare("
INSERT INTO messages
(sender_type,sender_id,receiver_type,receiver_id,message)
VALUES (?,?,?,?,?)
");
$stmt->bind_param("siiss",$me_type,$me_id,$type,$id,$msg);
$stmt->execute();
