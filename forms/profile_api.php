<?php
/**
 * Profile API Handler
 * Handles profile updates for parents, hospitals, and admins
 */

session_start();
include 'connection.php';
include 'csrf.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['parent_id']) && !isset($_SESSION['hospital_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Determine user type and ID
$user_type = null;
$user_id = null;

if (isset($_SESSION['parent_id'])) {
    $user_type = 'parent';
    $user_id = (int)$_SESSION['parent_id'];
} elseif (isset($_SESSION['hospital_id'])) {
    $user_type = 'hospital';
    $user_id = (int)$_SESSION['hospital_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $user_type = 'admin';
    $user_id = (int)$_SESSION['admin_id'];
}

// Handle GET request - fetch current profile
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_profile') {
    
    $table = $user_type . 's';
    $stmt = $conn->prepare("SELECT name, email, phone FROM $table WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();

    if ($profile) {
        echo json_encode([
            'success' => true,
            'name' => $profile['name'] ?? '',
            'email' => $profile['email'] ?? '',
            'phone' => $profile['phone'] ?? '',
            'csrf_token' => csrf_token()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Profile not found']);
    }
    exit;
}

// Handle POST request - update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    
    // Verify CSRF token
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate input
    if (!$name || !$email) {
        echo json_encode(['success' => false, 'message' => 'Name and email are required']);
        exit;
    }

    $table = $user_type . 's';

    if (!empty($password)) {
        // Update with new password
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE $table SET name = ?, email = ?, phone = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $name, $email, $phone, $hashed_password, $user_id);
    } else {
        // Update without password
        $stmt = $conn->prepare("UPDATE $table SET name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $email, $phone, $user_id);
    }

    if ($stmt->execute()) {
        error_log("profile_api.php: Profile updated. user_type=$user_type, user_id=$user_id, name=$name, email=$email");
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
    } else {
        error_log("profile_api.php: Profile UPDATE failed: " . $stmt->error);
        echo json_encode(['success' => false, 'message' => 'Error updating profile: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>
