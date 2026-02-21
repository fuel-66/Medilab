<?php
session_start();
// Use project's mysqli connection
include_once __DIR__ . '/connection.php';

if (!isset($_SESSION['user_id'])) {
	header("Location: login.php");
	exit;
}

if (!isset($conn) || !$conn) {
	header('Content-Type: text/plain; charset=utf-8');
	echo "Database connection not available. Ensure forms/connection.php exists and MySQL is running.";
	error_log('profile.php: $conn not available');
	exit;
}

// Fetch logged-in users from all roles
$users = [];

// Admins
 $res = $conn->query("SELECT name,'admin' as role,'https://i.pravatar.cc/100' as profile_pic FROM admins");
 $users = array_merge($users, $res->fetch_all(MYSQLI_ASSOC));

// Hospitals
 $res = $conn->query("SELECT name,'hospital' as role,'https://i.pravatar.cc/100' as profile_pic FROM hospitals");
 $users = array_merge($users, $res->fetch_all(MYSQLI_ASSOC));

// Parents
 $res = $conn->query("SELECT name,'parent' as role,'https://i.pravatar.cc/100' as profile_pic FROM parents");
 $users = array_merge($users, $res->fetch_all(MYSQLI_ASSOC));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile</title>
<style>
.user-card { display:inline-block; margin:10px; padding:10px; border:1px solid #ccc; border-radius:10px; text-align:center; }
.user-card img { border-radius:50%; width:80px; height:80px; }
</style>
</head>
<body>
<h1>Logged-in Users</h1>
<div>
<?php foreach($users as $u): ?>
<div class="user-card">
<img src="<?php echo $u['profile_pic']; ?>" alt="<?php echo $u['name']; ?>">
<h3><?php echo $u['name']; ?></h3>
<p><?php echo $u['role']; ?></p>
</div>
<?php endforeach; ?>
</div>
</body>
</html>

