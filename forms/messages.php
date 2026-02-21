<?php
session_start();
include 'connection.php';

/* --------------------------
   Determine logged-in user
   -------------------------- */
$user_type = null;
$user_id   = null;

if (isset($_SESSION['parent_id'])) {
    $user_type = 'parent';
    $user_id   = $_SESSION['parent_id'];
} elseif (isset($_SESSION['hospital_id'])) {
    $user_type = 'hospital';
    $user_id   = $_SESSION['hospital_id'];
} else {
    die('Not logged in');
}

/* Assign for rest of the code */
$myRole = $user_type;
$myId   = $user_id;

/* --------------------------
   Fetch chat list
   -------------------------- */
$users = [];
if ($myRole === 'parent') {
    // Show all hospitals
    $res = $conn->query("SELECT id, name FROM hospitals");
} elseif ($myRole === 'hospital') {
    // Show all parents
    $res = $conn->query("SELECT id, name FROM parents");
}

if (!$res) {
    die("Query failed: " . $conn->error);
}

while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}

/* --------------------------
   Selected chat
   -------------------------- */
$chatId = $_GET['chat_id'] ?? null;

/* --------------------------
   Determine receiver type
   -------------------------- */
$otherRole = ($myRole === 'parent') ? 'hospital' : 'parent';

/* --------------------------
   Send message
   -------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'], $_POST['receiver_id'])) {
    $msg = trim($_POST['message']);
    $rid = (int)$_POST['receiver_id'];

    if ($msg !== '') {
        $stmt = $conn->prepare("
            INSERT INTO messages
            (sender_type, sender_id, receiver_type, receiver_id, message, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("sisis", $myRole, $myId, $otherRole, $rid, $msg);
        $stmt->execute();
    }
    header("Location: messages.php?chat_id=$rid");
    exit;
}

/* --------------------------
   Fetch messages
   -------------------------- */
$messages = [];
if ($chatId) {
    $stmt = $conn->prepare("
        SELECT * FROM messages
        WHERE 
        (sender_type=? AND sender_id=? AND receiver_type=? AND receiver_id=?)
        OR
        (sender_type=? AND sender_id=? AND receiver_type=? AND receiver_id=?)
        ORDER BY created_at ASC
    ");
    $stmt->bind_param(
        "sisisisi",
        $myRole, $myId, $otherRole, $chatId,
        $otherRole, $chatId, $myRole, $myId
    );
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    /* Mark as read */
    $conn->query("
        UPDATE messages
        SET is_read=1, read_at=NOW()
        WHERE receiver_type='$myRole'
        AND receiver_id=$myId
        AND sender_id=$chatId
        AND read_at IS NULL
    ");
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Messages</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{margin:0;font-family:Arial;background:#ece5dd}
.app{display:flex;height:100vh}
.sidebar{width:30%;background:#fff;border-right:1px solid #ddd;overflow-y:auto}
.chat{flex:1;display:flex;flex-direction:column}
.header{padding:12px;background:#075e54;color:#fff;display:flex;align-items:center;gap:10px}
.user{padding:12px;border-bottom:1px solid #eee}
.user a{text-decoration:none;color:#000;display:block}
.user:hover{background:#f0f0f0}
.messages{flex:1;padding:15px;overflow-y:auto}
.bubble{max-width:60%;padding:10px;margin:5px;border-radius:8px;word-wrap:break-word}
.me{background:#dcf8c6;margin-left:auto}
.them{background:#fff}
.input{display:flex;padding:10px;background:#f0f0f0}
input{flex:1;padding:10px;border-radius:20px;border:1px solid #ccc}
button{margin-left:8px;padding:10px 16px;border:none;background:#075e54;color:#fff;border-radius:50%}
/* Back button style */
.back-btn{
    background:#25d366;color:#fff;padding:6px 12px;border-radius:5px;text-decoration:none;font-weight:bold;
}
</style>
</head>
<body>

<div class="app">
    <div class="sidebar">
        <div class="header">Chats</div>
        <?php foreach ($users as $u): ?>
            <div class="user">
                <a href="messages.php?chat_id=<?= $u['id'] ?>">
                    <?= htmlspecialchars($u['name']) ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="chat">
        <div class="header">
            <a href="dashboard.php" class="back-btn">&larr; Dashboard</a>
            <span><?= $chatId ? 'Chat' : 'Select a chat' ?></span>
        </div>

        <div class="messages">
            <?php foreach ($messages as $m): ?>
                <div class="bubble <?= $m['sender_id'] == $myId ? 'me' : 'them' ?>">
                    <?= htmlspecialchars($m['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($chatId): ?>
        <form class="input" method="POST">
            <input type="hidden" name="receiver_id" value="<?= $chatId ?>">
            <input name="message" placeholder="Type a message">
            <button><i class="fa fa-paper-plane"></i></button>
        </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
