<?php
// Redirect function
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Get user role
function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

// Sanitize input data
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Format date
function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

// Format datetime
function formatDateTime($datetime) {
    return date('M j, Y g:i A', strtotime($datetime));
}

// Get dashboard stats
function getDashboardStats($pdo, $user_id, $user_role) {
    $stats = [];
    
    if ($user_role === 'admin') {
        // Admin stats
        $sql = "SELECT 
                (SELECT COUNT(*) FROM parents) as total_parents,
                (SELECT COUNT(*) FROM hospitals) as total_hospitals,
                (SELECT COUNT(*) FROM bookings) as total_bookings,
                (SELECT COUNT(*) FROM bookings WHERE status = 'approved') as approved_bookings,
                (SELECT COUNT(*) FROM bookings WHERE status = 'pending') as pending_bookings";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    elseif ($user_role === 'hospital') {
        // Hospital stats
        $sql = "SELECT 
                (SELECT COUNT(*) FROM bookings WHERE hospital_id = ?) as total_bookings,
                (SELECT COUNT(*) FROM bookings WHERE hospital_id = ? AND status = 'approved') as approved_bookings,
                (SELECT COUNT(*) FROM bookings WHERE hospital_id = ? AND status = 'pending') as pending_bookings,
                (SELECT COUNT(*) FROM vaccines WHERE hospital_id = ?) as total_vaccines";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    elseif ($user_role === 'parent') {
        // Parent stats
        $sql = "SELECT 
                (SELECT COUNT(*) FROM children WHERE parent_id = ?) as total_children,
                (SELECT COUNT(*) FROM bookings WHERE parent_id = ?) as total_bookings,
                (SELECT COUNT(*) FROM bookings WHERE parent_id = ? AND status = 'approved') as approved_bookings,
                (SELECT COUNT(*) FROM bookings WHERE parent_id = ? AND status = 'pending') as pending_bookings";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $stats;
}

// Get chart data
function getChartData($pdo, $user_id, $user_role) {
    $data = [];
    
    if ($user_role === 'admin') {
        // Admin chart data - bookings per month
        $sql = "SELECT 
                MONTH(created_at) as month,
                COUNT(*) as count 
                FROM bookings 
                WHERE YEAR(created_at) = YEAR(CURDATE())
                GROUP BY MONTH(created_at)
                ORDER BY month";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $data;
}

// Check if user has access to resource
function hasAccess($required_role) {
    $user_role = getUserRole();
    if (is_array($required_role)) {
        return in_array($user_role, $required_role);
    }
    return $user_role === $required_role;
}

// Generate random password
function generatePassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}
?>