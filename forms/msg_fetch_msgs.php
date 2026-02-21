<?php
session_start();
include 'connection.php';

$me_type = isset($_SESSION['parent_id'])?'parent':'hospital';
$me_id   = $me_type==='parent'?$_SESSION['parent_id']:$_SESSION['hospital_id'];

$type = $_GET['type'];
$id   = (int)$_GET['id'];

$q = $conn->query("
SELECT * FROM messages
WHERE
(sender_type='$me_type' AND sender_id=$me_id AND receiver_type='$type' AND receiver_id=$id)
OR
(sender_type='$type' AND sender_id=$id AND receiver_type='$me_type' AND receiver_id=$me_id)
ORDER BY id ASC
");

$conn->query("
UPDATE messages SET is_read=1, read_at=NOW()
WHERE receiver_type='$me_type' AND receiver_id=$me_id
");

while($m=$q->fetch_assoc()):
$class = $m['sender_type']===$me_type?'sent':'received';
?>
<div class="msg <?= $class ?>">
    <?= htmlspecialchars($m['message']) ?>
    <div class="time">
        <?= date('H:i',strtotime($m['created_at'])) ?>
        <?= $class==='sent'?($m['is_read']?'✔✔':'✔'):'' ?>
    </div>
</div>
<?php endwhile; ?>
