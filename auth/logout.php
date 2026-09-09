<?php
/**
 * Logout Processing
 * RDM Information System
 */

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = [];

// Destroy session cookie on client browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session on server
session_destroy();

// Redirect to login with logout flag
header('Location: ../public/login.php?logged_out=1');
exit;
