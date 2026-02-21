<?php
session_start();
include 'connection.php';
$parent_id = (int)$_SESSION['parent_id'];
$child_id  = (int)($_GET['child_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT * FROM messages 
    WHERE (sender_type='parent' AND sender_id=?) OR (receiver_id=? AND child_id=?)
    ORDER BY created_at ASC
");
$stmt->bind_param("iii",$parent_id,$parent_id,$child_id);
$stmt->execute();
$messages = $stmt->get_result();
$stmt->close();

foreach($messages as $m){
    $cls = $m['sender_type'];
    $time = date('H:i', strtotime($m['created_at']));
    $status = ($cls=='parent') ? ($m['read_at']?'✔✔':'✔') : '';
    echo "<div class='message $cls'>".htmlspecialchars($m['message'])."<div class='time'>$time <span class='status'>$status</span></div></div>";
}

