<?php
// This script finds upcoming due dates and inserts notifications, and can send emails
// Run from CLI: php cron_reminders.php
include 'connection.php';

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// find reminders for tomorrow (or any due_date)
$q = $conn->prepare("SELECT r.*, p.email AS parent_email, p.id AS parent_id, c.name as child_name FROM reminders r JOIN parents p ON r.parent_id=p.id JOIN children c ON r.child_id=c.id WHERE r.due_date = ? AND r.is_sent = 0");
$q->bind_param("s", $tomorrow);
$q->execute();
$res = $q->get_result();

while ($row = $res->fetch_assoc()) {
    // create notification record
    $msg = "Reminder: ". $row['vaccine_name'] . " for " . $row['child_name'] . " is due on " . $row['due_date'];
    $stmt = $conn->prepare("INSERT INTO notifications (user_type, user_id, message) VALUES ('parent', ?, ?)");
    $stmt->bind_param("is", $row['parent_id'], $msg);
    $stmt->execute();
    $stmt->close();

    // optionally mark reminder as sent
    $upd = $conn->prepare("UPDATE reminders SET is_sent=1 WHERE id=?");
    $upd->bind_param("i", $row['id']);
    $upd->execute();
    $upd->close();

    // OPTIONAL: send email (requires mail setup)
    // mail($row['parent_email'], 'Vaccination Reminder', $msg, "From: no-reply@yourdomain.com");
}

echo "Reminders processed.\n";
