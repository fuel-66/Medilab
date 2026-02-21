<?php
// chat_api.php
session_start();
include 'connection.php';
header('Content-Type: application/json');

$parent_id = $_SESSION['parent_id'] ?? null;
$hospital_id = $_SESSION['hospital_id'] ?? null;
$role = $parent_id ? 'parent' : ($hospital_id ? 'hospital' : null);
$my_id = $parent_id ?? $hospital_id;

if (!$role) {
    echo json_encode(['error'=>'not_logged_in']); exit;
}

// POST -> send message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sender_type = $_POST['sender_type'] ?? '';
    $sender_id = (int)($_POST['sender_id'] ?? 0);
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $child_id = isset($_POST['child_id']) && $_POST['child_id'] !== '' ? (int)$_POST['child_id'] : null;
    $message = trim($_POST['message'] ?? '');

    if (!$sender_type || !$sender_id || !$receiver_id || $message === '') {
        echo json_encode(['error'=>'missing']); exit;
    }

    $stmt = $conn->prepare("INSERT INTO messages (sender_type, sender_id, receiver_id, child_id, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("siiis", $sender_type, $sender_id, $receiver_id, $child_id, $message);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        // add notification for receiver (we assume opposite type is 'parent' if sender is 'hospital' etc)
        $u_type = $sender_type === 'parent' ? 'hospital' : 'parent';
        $note_stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, message) VALUES (?, ?, ?)");
        $note_msg = "New message: " . substr($message,0,200);
        $note_stmt->bind_param("sis", $u_type, $receiver_id, $note_msg);
        $note_stmt->execute();
        $note_stmt->close();

        echo json_encode(['ok'=>true]);
    } else echo json_encode(['error'=>'db']);
    exit;
}

// GET -> fetch messages (last 500). We provide `me` flag to client.
$q = $conn->query("SELECT * FROM messages ORDER BY created_at ASC LIMIT 500");
$out = [];
while ($r = $q->fetch_assoc()) {
    $r['me'] = (($r['sender_type'] === $role) && ((int)$r['sender_id'] === (int)$my_id));
    $out[] = $r;
}
echo json_encode(['messages'=>$out]);
