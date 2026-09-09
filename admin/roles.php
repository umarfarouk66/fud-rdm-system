<?php
/**
 * Role Governance & Permissions Matrix
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

// Fetch all roles with active user counts
$rolesStmt = $pdo->query("
    SELECT 
        r.id,
        r.name,
        r.description,
        COUNT(u.id) AS total_users,
        SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_users
    FROM roles r
    LEFT JOIN users u ON r.id = u.role_id
    GROUP BY r.id, r.name, r.description
    ORDER BY r.id ASC
");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch permissions for each role
$rolePermissions = [];
try {
    $permStmt = $pdo->query("
        SELECT 
            rp.role_id,
            p.name AS permission_name,
            p.description AS permission_description
        FROM role_permissions rp
        INNER JOIN permissions p ON rp.permission_id = p.id
        ORDER BY p.name ASC
    ");
    while ($row = $permStmt->fetch(PDO::FETCH_ASSOC)) {
        $rolePermissions[$row['role_id']][] = $row;
    }
} catch (PDOException $e) {
    error_log("Role Permissions Query Error: " . $e->getMessage());
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles & Permissions — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .role-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .role-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
        }
        .role-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        .perm-list {
            list-style: none;
            padding: 0;
            margin: 0.75rem 0;
            flex-grow: 1;
            font-size: 0.85rem;
        }
        .perm-item {
            padding: 0.35rem 0;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            color: var(--text-main);
            border-bottom: 1px solid #f8fafc;
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

        <!-- Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🛡️ Role Governance & Permissions
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Review established system roles, user population distributions and permission matrices.
            </p>
        </div>

        <!-- Role Cards Grid -->
        <div class="role-cards-grid">
            <?php foreach ($roles as $r): 
                $rId = (int)$r['id'];
                $rName = strtolower(trim($r['name']));
                $perms = $rolePermissions[$rId] ?? [];
            ?>
                <div class="role-card">
                    <div class="role-card-header">
                        <span class="role-badge role-badge-<?= e($rName); ?>" style="font-size: 0.85rem;">
                            <?= e(ucfirst($rName)); ?>
                        </span>
                        <span style="font-size: 0.85rem; font-weight: 700; color: var(--primary-color);">
                            <?= (int)$r['active_users']; ?> active user(s)
                        </span>
                    </div>

                    <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                        <?= e($r['description']); ?>
                    </p>

                    <h4 style="font-size: 0.85rem; text-transform: uppercase; color: var(--primary-color); font-weight: 700; margin-bottom: 0.5rem;">
                        Assigned Capabilities (<?= count($perms); ?>)
                    </h4>

                    <ul class="perm-list">
                        <?php if (empty($perms)): ?>
                            <li style="color: var(--text-muted); font-style: italic;">No specific permissions bound.</li>
                        <?php else: ?>
                            <?php foreach ($perms as $p): ?>
                                <li class="perm-item">
                                    <span>✔</span>
                                    <div>
                                        <strong><?= e($p['permission_name']); ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($p['permission_description']); ?></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>

                    <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid #f1f5f9; text-align: right;">
                        <a href="users.php?role=<?= e($rName); ?>" style="font-size: 0.85rem; color: var(--accent-color); font-weight: 700; text-decoration: none;">
                            View <?= e(ucfirst($rName)); ?> Users &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
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
