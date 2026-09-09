<?php
/**
 * Login Processing
 * RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/auth_check.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../public/login.php');
    exit;
}

// Extract credentials
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Retain entered email for form re-population
$_SESSION['old_login_email'] = $email;

// Basic validation
if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter both your email address and password.';
    header('Location: ../public/login.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['login_error'] = 'Please enter a valid email address.';
    header('Location: ../public/login.php');
    exit;
}

try {
    // Look up user by email and join role information
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.role_id,
            u.first_name,
            u.last_name,
            u.email,
            u.password_hash,
            u.institution,
            u.faculty,
            u.department,
            u.status,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.email = :email
        LIMIT 1
    ");

    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Verify user existence and password hash
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_error'] = 'Invalid email address or password.';
        header('Location: ../public/login.php');
        exit;
    }

    // Check account status
    if ($user['status'] !== 'active') {
        if ($user['status'] === 'suspended') {
            $_SESSION['login_error'] = 'Your account has been suspended. Please contact the system administrator.';
        } else {
            $_SESSION['login_error'] = 'Your account is currently inactive. Please contact support.';
        }
        header('Location: ../public/login.php');
        exit;
    }

    // Authentication successful: Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Clear old login session flash data
    unset($_SESSION['old_login_email']);
    unset($_SESSION['login_error']);
    unset($_SESSION['auth_error']);
    unset($_SESSION['access_error']);

    // Set secure session variables
    $_SESSION['user_id']     = (int)$user['id'];
    $_SESSION['user_name']   = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['first_name']  = $user['first_name'];
    $_SESSION['last_name']   = $user['last_name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['role_id']     = (int)$user['role_id'];
    $_SESSION['role']        = strtolower(trim($user['role_name']));
    $_SESSION['institution'] = $user['institution'] ?? '';
    $_SESSION['faculty']     = $user['faculty'] ?? '';
    $_SESSION['department']  = $user['department'] ?? '';
    $_SESSION['logged_in']   = true;

    // Update last_login_at timestamp
    $updateStmt = $pdo->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updateStmt->execute([':id' => $user['id']]);

    // Role-based redirection
    $dashboardUrl = getDashboardUrl($_SESSION['role'], '../');
    header("Location: $dashboardUrl");
    exit;

} catch (PDOException $e) {
    error_log("Login DB Error: " . $e->getMessage());
    $_SESSION['login_error'] = 'A system error occurred during authentication. Please try again.';
    header('Location: ../public/login.php');
    exit;
}
