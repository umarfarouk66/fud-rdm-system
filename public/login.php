<?php
/**
 * User Login Page
 * RDM Information System
 */

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

// If user is already authenticated, redirect to their role dashboard
if (isLoggedIn()) {
    $dashboardUrl = getDashboardUrl($_SESSION['role'], '../');
    header("Location: $dashboardUrl");
    exit;
}

// Retrieve flash messages
$loginError  = $_SESSION['login_error'] ?? null;
$authError   = $_SESSION['auth_error'] ?? null;
$authSuccess = $_SESSION['auth_success'] ?? null;
$oldEmail    = $_SESSION['old_login_email'] ?? '';

// Check query parameter notices
if (isset($_GET['registered']) && empty($authSuccess)) {
    $authSuccess = 'Registration completed successfully! Please log in with your credentials.';
}
if (isset($_GET['logged_out']) && empty($authSuccess)) {
    $authSuccess = 'You have been successfully logged out.';
}

// Clear flash session variables
unset($_SESSION['login_error']);
unset($_SESSION['auth_error']);
unset($_SESSION['auth_success']);
unset($_SESSION['old_login_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=2.1">
</head>
<body>

    <!-- Site Header -->
    <header class="site-header">
        <a href="index.php" class="brand-container">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="brand-logo" style="height: 46px; max-height: 46px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="brand-title">FUD RDM System</div>
                <div class="brand-subtitle">Federal University Dutse</div>
            </div>
        </a>
        <nav class="nav-links">
            <a href="index.php" class="nav-link">Home</a>
            <a href="register.php" class="btn btn-outline">Register</a>
        </nav>
    </header>

    <!-- Main Content Area -->
    <main class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-header" style="text-align: center;">
                <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 56px; width: auto; object-fit: contain; margin-bottom: 0.75rem;">
                <h2>Welcome Back</h2>
                <p>Sign in to access your research datasets and projects</p>
            </div>

            <!-- Notification Messages -->
            <?php if (!empty($authSuccess)): ?>
                <div class="alert alert-success">
                    <?= e($authSuccess); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger">
                    <?= e($loginError); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($authError)): ?>
                <div class="alert alert-warning">
                    <?= e($authError); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form action="../auth/login_process.php" method="POST" autocomplete="on">
                <?= csrf_field(); ?>

                <div class="form-group">
                    <label for="email" class="form-label">Academic Email Address <span class="required">*</span></label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="e.g. researcher@fud.edu.ng" 
                        value="<?= e($oldEmail); ?>" 
                        required 
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password <span class="required">*</span></label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Enter your password" 
                        required
                    >
                </div>

                <div class="form-group" style="margin-top: 1.75rem;">
                    <button type="submit" class="btn btn-primary btn-block">Sign In</button>
                </div>
            </form>

            <div style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
                Don't have an account? 
                <a href="register.php" style="color: var(--accent-color); font-weight: 600; text-decoration: none;">Register here</a>
            </div>
        </div>
    </main>

    <!-- Site Footer -->
    <footer class="site-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
