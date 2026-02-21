<?php
session_start();
include 'connection.php';
include 'csrf.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["error" => "Invalid request"]);
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? "")) {
    echo json_encode(["error" => "CSRF failed"]);
    exit;
}

$message  = trim($_POST['message'] ?? "");
$child_id = isset($_POST['child_id']) && $_POST['child_id'] !== '' ? (int)$_POST['child_id'] : null;
$receiver_id = (int)($_POST['receiver_id'] ?? 0);

if ($message === "" || !$receiver_id) {
    echo json_encode(["error" => "Missing fields"]);
    exit;
}

/* Detect sender */
if (isset($_SESSION['parent_id'])) {
    $sender_type = "parent";
    $sender_id   = $_SESSION['parent_id'];
} elseif (isset($_SESSION['hospital_id'])) {
    $sender_type = "hospital";
    $sender_id   = $_SESSION['hospital_id'];
} else {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Insert message (read_at = NULL by default)
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare("
    INSERT INTO messages (sender_type, sender_id, receiver_id, child_id, message)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("siiis", $sender_type, $sender_id, $receiver_id, $child_id, $message);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["error" => $stmt->error]);
}
$stmt->close();
