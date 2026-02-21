<?php
// Compatibility wrapper: original project uses 'funcation.php' (misspelled).
// Create `functions.php` to include the existing file and avoid broken require paths.

// Use __DIR__ to ensure correct path regardless of current working directory.
$func_path = __DIR__ . DIRECTORY_SEPARATOR . 'funcation.php';
if (file_exists($func_path)) {
    require_once $func_path;
} else {
    // If the misspelled file is missing, create fallback minimal implementations
    // to prevent fatal errors. These are small safe defaults; replace with
    // proper implementations if needed.
    function redirect($url) {
        header("Location: " . $url);
        exit();
    }
    function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }
    function formatDate($date) {
        return $date ? date('M j, Y', strtotime($date)) : '';
    }
}
?>