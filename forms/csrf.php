<?php
// csrf.php
if (session_status() === PHP_SESSION_NONE) session_start();

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token()).'">';
}

function csrf_verify($token) {
    if (empty($_SESSION['csrf_token']) || !$token) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}
