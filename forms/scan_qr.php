<?php
include 'connection.php';
session_start();

// POST raw payload (child_id=2&parent_id=1)
$payload = $_POST['payload'] ?? $_GET['payload'] ?? null;
if (!$payload) {
    echo "Provide payload=child_id=2";
    exit;
}
parse_str($payload, $out);
if (!empty($out['child_id'])) {
    $cid = (int)$out['child_id'];
    $child = $conn->query("SELECT c.*, p.name as parent_name, p.phone as parent_phone FROM children c JOIN parents p ON c.parent_id=p.id WHERE c.id=$cid")->fetch_assoc();
    if ($child) {
        echo "<h3>Child: ".htmlspecialchars($child['name'])."</h3>";
        echo "Parent: ".htmlspecialchars($child['parent_name'])."<br>";
        echo "DOB: ".htmlspecialchars($child['date_of_birth'])."<br>";
    } else echo "No child record";
} else {
    echo "Invalid payload";
}
