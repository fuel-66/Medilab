<?php
// typing.php - minimal typing presence using filesystem (for dev)
session_start();
header('Content-Type: application/json');
include 'connection.php';

$action = $_REQUEST['action'] ?? '';
$sender_type = $_REQUEST['sender_type'] ?? '';
$sender_id = isset($_REQUEST['sender_id']) ? (int)$_REQUEST['sender_id'] : 0;
$receiver_id = isset($_REQUEST['receiver_id']) ? (int)$_REQUEST['receiver_id'] : 0;

if (!$sender_type || !$sender_id || !$receiver_id) {
    echo json_encode(['ok'=>false]);
    exit;
}

$dir = __DIR__ . '/tmp';
if (!is_dir($dir)) mkdir($dir,0755,true);
$key = "typing_{$sender_type}_{$sender_id}_to_{$receiver_id}.json";
$path = $dir . '/' . $key;

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = ['sender_type'=>$sender_type,'sender_id'=>$sender_id,'receiver_id'=>$receiver_id,'ts'=>time()];
    file_put_contents($path, json_encode($data));
    echo json_encode(['ok'=>true]);
    exit;
}

// read status: check if a typing file exists for the other party toward the viewer
// e.g., caller requests other party's typing status by passing sender/receiver swapped
if ($action === 'check') {
    $other_path = $dir . "/typing_{$sender_type}_{$sender_id}_to_{$receiver_id}.json";
    if (file_exists($other_path)) {
        $d = json_decode(file_get_contents($other_path), true);
        if ($d && isset($d['ts']) && (time() - $d['ts'] <= 6)) {
            echo json_encode(['ok'=>true,'typing'=>true]);
            exit;
        }
    }
    echo json_encode(['ok'=>true,'typing'=>false]);
    exit;
}

echo json_encode(['ok'=>false]);
