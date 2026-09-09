<?php
/**
 * Safe System Settings & Configuration
 * RDM Information System - Step 14
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/admin_helpers.php';

// Enforce administrator role
requireRole('admin');

$adminUser = currentUser();
$adminId   = (int)$adminUser['id'];

$feedbackMessage = '';
$errorMessage    = '';

// Load current settings
$settings = getSystemSettings();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission();
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        $systemName      = trim($_POST['system_name'] ?? '');
        $institutionName = trim($_POST['institution_name'] ?? '');
        $contactEmail    = trim($_POST['contact_email'] ?? '');
        $itemsPerPage    = (int)($_POST['items_per_page'] ?? 20);
        $maintenanceMsg  = trim($_POST['maintenance_notice'] ?? '');

        $errors = [];

        if ($systemName === '') {
            $errors[] = 'System Name is required.';
        }
        if ($institutionName === '') {
            $errors[] = 'Institution Name is required.';
        }
        if ($contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid Contact & Support Email address is required.';
        }
        if ($itemsPerPage < 10 || $itemsPerPage > 50) {
            $errors[] = 'Default items per page must be between 10 and 50.';
        }

        if (!empty($errors)) {
            $errorMessage = implode(' ', $errors);
        } else {
            $newSettings = [
                'system_name'         => $systemName,
                'institution_name'    => $institutionName,
                'contact_email'       => $contactEmail,
                'items_per_page'      => $itemsPerPage,
                'maintenance_notice'  => $maintenanceMsg,
                'enable_registration' => !empty($_POST['enable_registration'])
            ];

            if (saveSystemSettings($newSettings)) {
                $settings = getSystemSettings();

                // Audit settings update
                log_audit(
                    $pdo,
                    'settings_updated',
                    'system_settings',
                    null,
                    "Administrator '{$adminUser['name']}' (#{$adminId}) updated institutional system configuration"
                );

                $feedbackMessage = 'System settings updated and saved successfully.';
            } else {
                $errorMessage = 'Failed to write settings to storage configuration file.';
            }
        }
    }
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .settings-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 2rem;
            max-width: 800px;
            margin: 1.5rem auto;
            box-shadow: var(--shadow-sm);
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .form-group input, .form-group select, .form-group textarea {
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            background: #ffffff;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--accent-color);
            outline: none;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Admin Console</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="../notifications/index.php" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; margin-right: 0.75rem; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);" title="Notifications">
                <span>🔔</span>
                <?php if ($notifUnreadCount > 0): ?>
                    <span style="background: #059669; color: #ffffff; font-size: 0.75rem; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                        <?= $notifUnreadCount; ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($adminUser['name']); ?></div>
                    <div class="user-affiliation">System Administrator</div>
                </div>
                <span class="role-badge role-badge-admin">Admin</span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
        </div>

        <!-- Feedback Alert -->
        <?php if (!empty($feedbackMessage)): ?>
            <div class="dash-alert dash-alert-success" style="max-width: 800px; margin: 0 auto 1.5rem auto;">
                <span><?= e($feedbackMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="dash-alert dash-alert-danger" style="max-width: 800px; margin: 0 auto 1.5rem auto;">
                <span><?= e($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="settings-card">
            <h1 style="font-size: 1.5rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                ⚙️ Safe System Settings
            </h1>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                Configure repository branding, default presentation limits and institutional contact points.
            </p>

            <form method="POST" action="settings.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="system_name">System / Portal Name *</label>
                        <input type="text" id="system_name" name="system_name" value="<?= e($settings['system_name']); ?>" required maxlength="150">
                    </div>

                    <div class="form-group">
                        <label for="institution_name">Host Institution *</label>
                        <input type="text" id="institution_name" name="institution_name" value="<?= e($settings['institution_name']); ?>" required maxlength="150">
                    </div>

                    <div class="form-group">
                        <label for="contact_email">Support & Inquiries Email *</label>
                        <input type="email" id="contact_email" name="contact_email" value="<?= e($settings['contact_email']); ?>" required maxlength="150">
                    </div>

                    <div class="form-group">
                        <label for="items_per_page">Default Records Per Page *</label>
                        <select id="items_per_page" name="items_per_page" required>
                            <option value="10" <?= ((int)$settings['items_per_page'] === 10) ? 'selected' : ''; ?>>10 records</option>
                            <option value="20" <?= ((int)$settings['items_per_page'] === 20) ? 'selected' : ''; ?>>20 records (Recommended)</option>
                            <option value="30" <?= ((int)$settings['items_per_page'] === 30) ? 'selected' : ''; ?>>30 records</option>
                            <option value="50" <?= ((int)$settings['items_per_page'] === 50) ? 'selected' : ''; ?>>50 records</option>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="maintenance_notice">Institutional Announcement / Banner Notice</label>
                        <textarea id="maintenance_notice" name="maintenance_notice" rows="3" placeholder="Optional announcement displayed on repository landing pages..."><?= e($settings['maintenance_notice']); ?></textarea>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border-color);">
                    <button type="submit" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.6rem 1.5rem; font-weight: 700; border-radius: var(--radius-sm); border: none; cursor: pointer;">
                        Save System Settings
                    </button>
                </div>
            </form>
        </div>

    </main>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
