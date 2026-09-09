<?php
/**
 * User Profile & Activity View
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

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    $_SESSION['admin_error'] = 'Invalid user ID specified.';
    header('Location: users.php');
    exit;
}

// Fetch user profile
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.institution,
        u.faculty,
        u.department,
        u.status,
        u.created_at,
        u.updated_at,
        u.last_login_at,
        u.role_id,
        r.name AS role_name,
        r.description AS role_description
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRecord) {
    $_SESSION['admin_error'] = 'The requested user account was not found.';
    header('Location: users.php');
    exit;
}

// Fetch associated activity statistics
$stats = [
    'projects_owned'     => 0,
    'projects_member'    => 0,
    'datasets_created'   => 0,
    'access_requests'    => 0
];

try {
    $pStmt = $pdo->prepare("SELECT COUNT(*) FROM research_projects WHERE owner_id = ?");
    $pStmt->execute([$userId]);
    $stats['projects_owned'] = (int)$pStmt->fetchColumn();

    $pmStmt = $pdo->prepare("SELECT COUNT(*) FROM project_members WHERE user_id = ?");
    $pmStmt->execute([$userId]);
    $stats['projects_member'] = (int)$pmStmt->fetchColumn();

    $dStmt = $pdo->prepare("SELECT COUNT(*) FROM datasets WHERE owner_id = ?");
    $dStmt->execute([$userId]);
    $stats['datasets_created'] = (int)$dStmt->fetchColumn();

    $aStmt = $pdo->prepare("SELECT COUNT(*) FROM access_requests WHERE requester_id = ?");
    $aStmt->execute([$userId]);
    $stats['access_requests'] = (int)$aStmt->fetchColumn();

    // Fetch recent audit logs for this user (performed by or targeting)
    $auditStmt = $pdo->prepare("
        SELECT id, action, entity_type, entity_id, description, ip_address, created_at
        FROM audit_logs
        WHERE user_id = :uid OR (entity_type = 'users' AND entity_id = :uid)
        ORDER BY created_at DESC, id DESC
        LIMIT 10
    ");
    $auditStmt->execute([':uid' => $userId]);
    $recentActivity = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("User View Stats Error: " . $e->getMessage());
    $recentActivity = [];
}

$fullName = trim($userRecord['first_name'] . ' ' . $userRecord['last_name']);
$roleName = strtolower(trim($userRecord['role_name']));
$status   = strtolower(trim($userRecord['status']));

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User: <?= e($fullName); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .profile-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        @media (max-width: 850px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }
        .profile-sidebar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .profile-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #3730a3;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1rem auto;
            font-weight: 700;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.65rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.875rem;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: var(--text-muted);
            font-weight: 600;
        }
        .detail-value {
            color: var(--text-main);
            font-weight: 700;
            text-align: right;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            display: inline-block;
        }
        .status-active    { background: #d1fae5; color: #065f46; }
        .status-inactive  { background: #f1f5f9; color: #475569; }
        .status-suspended { background: #fee2e2; color: #991b1b; }
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
            <a href="users.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to User Directory
            </a>
        </div>

        <!-- Header Actions -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                    👤 <?= e($fullName); ?>
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    User Account #<?= $userId; ?> &bull; <?= e(ucfirst($roleName)); ?> &bull; Registered <?= date('M d, Y', strtotime($userRecord['created_at'])); ?>
                </p>
            </div>
            <div style="display: flex; gap: 0.75rem; align-items: center;">
                <a href="user_edit.php?id=<?= $userId; ?>" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.55rem 1.25rem; font-weight: 600; border-radius: var(--radius-sm); text-decoration: none;">
                    ✏️ Edit User Profile
                </a>
            </div>
        </div>

        <!-- Profile Layout -->
        <div class="profile-layout">

            <!-- Sidebar Info -->
            <div class="profile-sidebar">
                <div class="profile-avatar">
                    <?= strtoupper(substr($userRecord['first_name'], 0, 1) . substr($userRecord['last_name'], 0, 1)); ?>
                </div>
                <h3 style="text-align: center; font-size: 1.15rem; color: var(--primary-color); margin-bottom: 0.25rem;">
                    <?= e($fullName); ?>
                </h3>
                <div style="text-align: center; margin-bottom: 1.25rem;">
                    <span class="role-badge role-badge-<?= e($roleName); ?>">
                        <?= e(ucfirst($roleName)); ?>
                    </span>
                    <span class="status-badge status-<?= e($status); ?>" style="margin-left: 0.35rem;">
                        <?= e(ucfirst($status)); ?>
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-label">Email</span>
                    <span class="detail-value"><?= e($userRecord['email']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone</span>
                    <span class="detail-value"><?= e($userRecord['phone'] ?: 'Not specified'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Institution</span>
                    <span class="detail-value"><?= e($userRecord['institution'] ?: 'Institutional'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Faculty</span>
                    <span class="detail-value"><?= e($userRecord['faculty'] ?: '—'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Department</span>
                    <span class="detail-value"><?= e($userRecord['department'] ?: '—'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Registered</span>
                    <span class="detail-value"><?= date('M d, Y H:i', strtotime($userRecord['created_at'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Last Login</span>
                    <span class="detail-value"><?= !empty($userRecord['last_login_at']) ? date('M d, Y H:i', strtotime($userRecord['last_login_at'])) : 'Never'; ?></span>
                </div>
            </div>

            <!-- Main Content Area -->
            <div>
                <!-- Stat Cards -->
                <div class="stats-grid" style="margin-bottom: 1.5rem;">
                    <div class="stat-card">
                        <div class="stat-icon-box icon-emerald">📁</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $stats['projects_owned']; ?></div>
                            <div class="stat-label">Projects Owned</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-box icon-blue">👥</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $stats['projects_member']; ?></div>
                            <div class="stat-label">Team Memberships</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-box icon-purple">📊</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $stats['datasets_created']; ?></div>
                            <div class="stat-label">Datasets Created</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-box icon-amber">🔑</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $stats['access_requests']; ?></div>
                            <div class="stat-label">Access Requests</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Audit Logs -->
                <div class="content-card">
                    <h2 class="section-title" style="margin-bottom: 1rem;">Recent System Activity & Audit Trail</h2>
                    <?php if (empty($recentActivity)): ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">No recent audit activity recorded for this user.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
                                <thead>
                                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--border-color);">
                                        <th style="padding: 0.65rem 0.75rem;">Timestamp</th>
                                        <th style="padding: 0.65rem 0.75rem;">Action</th>
                                        <th style="padding: 0.65rem 0.75rem;">Description</th>
                                        <th style="padding: 0.65rem 0.75rem;">IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentActivity as $act): ?>
                                        <tr style="border-bottom: 1px solid #f1f5f9;">
                                            <td style="padding: 0.65rem 0.75rem; white-space: nowrap; color: var(--text-muted);">
                                                <?= date('M d, Y H:i', strtotime($act['created_at'])); ?>
                                            </td>
                                            <td style="padding: 0.65rem 0.75rem; white-space: nowrap;">
                                                <span style="font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 0.15rem 0.45rem; border-radius: 4px; font-size: 0.75rem;">
                                                    <?= e($act['action']); ?>
                                                </span>
                                            </td>
                                            <td style="padding: 0.65rem 0.75rem; color: var(--text-main);">
                                                <?= e($act['description']); ?>
                                            </td>
                                            <td style="padding: 0.65rem 0.75rem; color: var(--text-muted); font-family: monospace;">
                                                <?= e($act['ip_address']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

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
