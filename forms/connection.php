<?php
// connection.php
$host = "localhost";
$user = "root";
$pass = ""; // default XAMPP MySQL password is empty for 'root'
$db   = "vaxcare_pro";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    // Try connecting using 'localhost' first
    $conn = new mysqli($host, $user, $pass, $db);
} catch (mysqli_sql_exception $e) {
    // If connecting to 'localhost' fails, try 127.0.0.1 explicitly (different transport on some systems)
    try {
        $conn = new mysqli('127.0.0.1', $user, $pass, $db, 3306);
    } catch (mysqli_sql_exception $e2) {
        // Provide a helpful error message rather than an uncaught exception
        $msg  = "Database Connection Failed: " . $e2->getMessage() . "\n";
        $msg .= "Common causes:\n";
        $msg .= " - MySQL server is not running (start it from XAMPP Control Panel).\n";
        $msg .= " - Wrong credentials in forms/connection.php.\n";
        $msg .= " - Firewall blocking port 3306.\n";
        $msg .= "Troubleshooting: open XAMPP and ensure MySQL is running, then refresh this page.";
        // Log the actual exception to the PHP error log and show a friendly message to the browser
        error_log($e2->getMessage());
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}

$conn->set_charset("utf8mb4");
date_default_timezone_set('Asia/Karachi');

