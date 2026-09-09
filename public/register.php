<?php
/**
 * User Registration Page
 * RDM Information System
 */

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    $dashboardUrl = getDashboardUrl($_SESSION['role'], '../');
    header("Location: $dashboardUrl");
    exit;
}

// Retrieve flash errors and old input
$errors    = $_SESSION['register_errors'] ?? [];
$oldInput  = $_SESSION['old_register'] ?? [];

// Clear flash data
unset($_SESSION['register_errors']);
unset($_SESSION['old_register']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — FUD RDM System</title>
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
            <a href="login.php" class="btn btn-outline">Sign In</a>
        </nav>
    </header>

    <!-- Main Registration Area -->
    <main class="auth-wrapper">
        <div class="auth-card auth-card-wide">
            <div class="auth-header" style="text-align: center;">
                <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 56px; width: auto; object-fit: contain; margin-bottom: 0.75rem;">
                <h2>Create Your Academic Account</h2>
                <p>Register to manage research projects, datasets and data management plans</p>
            </div>

            <!-- Validation Error List -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Please resolve the following issues:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="../auth/register_process.php" method="POST" autocomplete="on">
                <?= csrf_field(); ?>

                <!-- Name Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name" class="form-label">First Name <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            class="form-control" 
                            placeholder="e.g. Marie"
                            value="<?= e($oldInput['first_name'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="last_name" class="form-label">Last Name <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="last_name" 
                            name="last_name" 
                            class="form-control" 
                            placeholder="e.g. Curie"
                            value="<?= e($oldInput['last_name'] ?? ''); ?>" 
                            required
                        >
                    </div>
                </div>

                <!-- Contact Fields -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="email" class="form-label">Academic Email Address <span class="required">*</span></label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="e.g. m.curie@university.edu"
                            value="<?= e($oldInput['email'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input 
                            type="tel" 
                            id="phone" 
                            name="phone" 
                            class="form-control" 
                            placeholder="e.g. +1 555-0199"
                            value="<?= e($oldInput['phone'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <!-- Institutional Affiliation -->
                <div class="form-group">
                    <label for="institution" class="form-label">Institution / Organization</label>
                    <input 
                        type="text" 
                        id="institution" 
                        name="institution" 
                        class="form-control" 
                        placeholder="e.g. University of Science and Technology"
                        value="<?= e($oldInput['institution'] ?? ''); ?>"
                    >
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="faculty" class="form-label">Faculty / School</label>
                        <input 
                            type="text" 
                            id="faculty" 
                            name="faculty" 
                            class="form-control" 
                            placeholder="e.g. Faculty of Natural Sciences"
                            value="<?= e($oldInput['faculty'] ?? ''); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="department" class="form-label">Department</label>
                        <input 
                            type="text" 
                            id="department" 
                            name="department" 
                            class="form-control" 
                            placeholder="e.g. Physics and Astronomy"
                            value="<?= e($oldInput['department'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <!-- Role Selection (Excluding supervisor and admin for public registration) -->
                <div class="form-group">
                    <label for="role" class="form-label">System Role <span class="required">*</span></label>
                    <select id="role" name="role" class="form-control" required>
                        <option value="">-- Select Your Role --</option>
                        <option value="researcher" <?= (($oldInput['role'] ?? '') === 'researcher') ? 'selected' : ''; ?>>
                            Student / Student Researcher (Create and manage research projects & data)
                        </option>
                        <option value="librarian" <?= (($oldInput['role'] ?? '') === 'librarian') ? 'selected' : ''; ?>>
                            Librarian / Data Steward (Curate metadata and repository deposits)
                        </option>
                    </select>
                    <p class="form-hint">Note: Supervisor and Administrator accounts must be assigned by the System Administrator and cannot be created via public self-registration.</p>
                </div>

                <!-- Passwords -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="password" class="form-label">Password <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Minimum 8 characters"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="required">*</span></label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-control" 
                            placeholder="Re-enter password"
                            required
                        >
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1.75rem;">
                    <button type="submit" class="btn btn-primary btn-block">Complete Registration</button>
                </div>
            </form>

            <div style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
                Already have an account? 
                <a href="login.php" style="color: var(--accent-color); font-weight: 600; text-decoration: none;">Sign in here</a>
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
