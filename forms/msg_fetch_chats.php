<?php
session_start();
include 'connection.php';

$me_type = isset($_SESSION['parent_id'])?'parent':'hospital';
$me_id   = $me_type==='parent'?$_SESSION['parent_id']:$_SESSION['hospital_id'];

$q = $conn->query("
SELECT receiver_type,receiver_id,COUNT(*) unread
FROM messages
WHERE receiver_type='$me_type' AND receiver_id=$me_id AND is_read=0
GROUP BY sender_id,sender_type
");

while($c=$q->fetch_assoc()):
?>
<div class="chat-user" onclick="openChat('<?= $c['receiver_type']=='parent'?'hospital':'parent' ?>',<?= $c['receiver_id'] ?>,'Chat')">
    Chat
    <?php if($c['unread']>0): ?>
        <span class="badge"><?= $c['unread'] ?></span>
    <?php endif; ?>
</div>
<?php endwhile; ?>
