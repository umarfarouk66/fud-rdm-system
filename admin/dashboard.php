<?php
/**
 * System Administrator Dashboard
 * RDM Information System - Step 14
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/admin_helpers.php';

// Enforce admin role
requireRole('admin');

$adminUser = currentUser();
$adminId   = (int)$adminUser['id'];
$accessError = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_error']);

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);

// Dynamic database metrics
$userStats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'suspended' => 0];
$repoStats = [
    'total_projects'    => 0,
    'total_datasets'    => 0,
    'public_datasets'   => 0,
    'restricted_datasets'=> 0,
    'private_datasets'  => 0,
    'preserved_datasets'=> 0,
    'archived_datasets' => 0,
    'pending_requests'  => 0,
    'total_logs'        => 0
];
$roleDistribution = [];

try {
    // 1. Users breakdown
    $uStmt = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count
        FROM users
    ");
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
        $userStats['total']     = (int)$uRow['total'];
        $userStats['active']    = (int)$uRow['active_count'];
        $userStats['inactive']  = (int)$uRow['inactive_count'];
        $userStats['suspended'] = (int)$uRow['suspended_count'];
    }

    // 2. Projects count
    $repoStats['total_projects'] = (int)$pdo->query("SELECT COUNT(*) FROM research_projects")->fetchColumn();

    // 3. Datasets breakdown
    $dStmt = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN access_level = 'public' THEN 1 ELSE 0 END) AS public_count,
            SUM(CASE WHEN access_level = 'restricted' THEN 1 ELSE 0 END) AS restricted_count,
            SUM(CASE WHEN access_level = 'private' THEN 1 ELSE 0 END) AS private_count,
            SUM(CASE WHEN status = 'preserved' THEN 1 ELSE 0 END) AS preserved_count,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived_count
        FROM datasets
        WHERE status != 'deleted'
    ");
    $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
    if ($dRow) {
        $repoStats['total_datasets']       = (int)$dRow['total'];
        $repoStats['public_datasets']      = (int)$dRow['public_count'];
        $repoStats['restricted_datasets']  = (int)$dRow['restricted_count'];
        $repoStats['private_datasets']     = (int)$dRow['private_count'];
        $repoStats['preserved_datasets']   = (int)$dRow['preserved_count'];
        $repoStats['archived_datasets']    = (int)$dRow['archived_count'];
    }

    // 4. Pending Access Requests
    $repoStats['pending_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'")->fetchColumn();

    // 5. Total Audit Logs
    $repoStats['total_logs'] = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

    // 6. Users per role breakdown
    $rStmt = $pdo->query("
        SELECT r.name AS role_name, COUNT(u.id) AS user_count
        FROM roles r
        LEFT JOIN users u ON r.id = u.role_id
        GROUP BY r.id, r.name
        ORDER BY r.id ASC
    ");
    $roleDistribution = $rStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Admin Dashboard Query Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .admin-nav-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-top: 1.5rem;
        }
        .admin-nav-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow-sm);
            transition: transform 0.15s ease, border-color 0.15s ease;
            display: flex;
            flex-direction: column;
        }
        .admin-nav-card:hover {
            transform: translateY(-2px);
            border-color: var(--accent-color);
        }
        .nav-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }
        .nav-card-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            background: #f8fafc;
            border: 1px solid var(--border-color);
        }
        .nav-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .nav-card-desc {
            font-size: 0.875rem;
            color: var(--text-muted);
            line-height: 1.4;
            flex-grow: 1;
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
            <a href="dashboard.php" class="btn-home-nav" style="text-decoration: none; color: #1e3a8a; display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 700; background: #ffffff; padding: 0.45rem 0.9rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); font-size: 0.85rem; margin-right: 0.5rem;" title="Go to Home Dashboard">
                <span>🏠 Home</span>
            </a>
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
                    <div class="user-affiliation"><?= isSuperAdmin() ? 'Institutional Monitor' : 'System Administrator'; ?></div>
                </div>
                <span class="role-badge role-badge-admin" style="<?= isSuperAdmin() ? 'background: #4338ca; color: #ffffff;' : ''; ?>">
                    <?= isSuperAdmin() ? 'Super Admin' : 'Admin'; ?>
                </span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Flash Notice if redirected -->
        <?php if (!empty($accessError)): ?>
            <div class="dash-alert dash-alert-danger">
                <span><?= e($accessError); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['admin_error'])): ?>
            <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem;">
                <span><?= e($_SESSION['admin_error']); ?></span>
            </div>
            <?php unset($_SESSION['admin_error']); ?>
        <?php endif; ?>

        <?php if (isSuperAdmin()): ?>
            <div class="dash-alert" style="background: #e0e7ff; border-color: #6366f1; color: #312e81; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">👁️</span>
                <div>
                    <strong>Super Admin Read-Only Mode:</strong> You are currently signed in as Super Admin. You have complete institutional monitoring visibility across all metrics, projects, users, reports and audit logs, but modification operations are restricted server-side.
                </div>
            </div>
        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="welcome-card" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);">
            <div class="welcome-text">
                <h1>Administrator Control Center</h1>
                <p>Monitor system health, manage institutional users, govern role assignments, review security audit logs and maintain repository configuration.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="users.php" style="display: inline-flex; align-items: center; background: #ffffff; color: #1e1b4b; font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                    👥 Manage Users
                </a>
                <a href="audit_logs.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none;">
                    🛡️ Audit Trail
                </a>
                <a href="system_status.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none;">
                    🖥️ System Health
                </a>
            </div>
        </div>

        <!-- Metric Cards Grid -->
        <div class="stats-grid">
            <a href="users.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-blue">👥</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $userStats['total']; ?></div>
                        <div class="stat-label">Total Users (<?= $userStats['active']; ?> Active)</div>
                    </div>
                </div>
            </a>

            <a href="../projects/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-emerald">📁</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $repoStats['total_projects']; ?></div>
                        <div class="stat-label">Total Research Projects</div>
                    </div>
                </div>
            </a>

            <a href="../datasets/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-purple">📊</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $repoStats['total_datasets']; ?></div>
                        <div class="stat-label">Total Datasets (<?= $repoStats['preserved_datasets']; ?> Preserved)</div>
                    </div>
                </div>
            </a>

            <a href="../access/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-amber">⏳</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $repoStats['pending_requests']; ?></div>
                        <div class="stat-label">Pending Access Requests</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Role Distribution Breakdown -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-title" style="margin: 0;">Institutional User Distribution by Role</h2>
                <a href="roles.php" style="color: var(--accent-color); font-weight: 700; font-size: 0.85rem; text-decoration: none;">
                    Role Governance &rarr;
                </a>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <?php foreach ($roleDistribution as $role): ?>
                    <a href="users.php?role=<?= e($role['role_name']); ?>" style="text-decoration: none; color: inherit;">
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: var(--radius-sm); text-align: center; transition: background 0.15s ease;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color);">
                                <?= (int)$role['user_count']; ?>
                            </div>
                            <div style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); margin-top: 0.25rem;">
                                <?= e($role['role_name']); ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Administrative Modules Navigation Cards -->
        <h2 class="section-title">Administrative Control Centers</h2>
        <div class="admin-nav-cards">

            <a href="users.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">👥</div>
                    <div class="nav-card-title">User Directory</div>
                </div>
                <div class="nav-card-desc">
                    Search, inspect and manage user accounts, activate or deactivate profiles and assign roles.
                </div>
            </a>

            <a href="academic_structure.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🏛️</div>
                    <div class="nav-card-title">Academic Structure</div>
                </div>
                <div class="nav-card-desc">
                    Configure institutional faculties, departments, degree programmes and academic sessions.
                </div>
            </a>

            <a href="assign_supervisor.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">👨‍🏫</div>
                    <div class="nav-card-title">Student–Supervisor Assignment</div>
                </div>
                <div class="nav-card-desc">
                    Assign students to supervisors, manage workload capacities, process reassignments and track unassigned students.
                </div>
            </a>

            <a href="supervision_overview.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">📊</div>
                    <div class="nav-card-title">Supervision & Progress Center</div>
                </div>
                <div class="nav-card-desc">
                    University-wide progress tracking, milestone due dates, pending reviews, open corrections, and supervision metrics.
                </div>
            </a>

            <a href="conversations.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">💬</div>
                    <div class="nav-card-title">Supervision Messaging Monitoring</div>
                </div>
                <div class="nav-card-desc">
                    Institutional oversight of student-supervisor communication activity, message volume, timestamps and compliance history.
                </div>
            </a>

            <a href="defense_management.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🎓</div>
                    <div class="nav-card-title">Defense & Viva Management</div>
                </div>
                <div class="nav-card-desc">
                    Schedule oral defenses, appoint examination panels, record viva outcomes, and issue final institutional project completion sign-offs.
                </div>
            </a>

            <a href="../reports/index.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">📈</div>
                    <div class="nav-card-title">Institutional Reports & Analytics</div>
                </div>
                <div class="nav-card-desc">
                    Comprehensive institutional analytics, supervisor workload reports, unassigned student tracking, and CSV report export center.
                </div>
            </a>

            <a href="audit_logs.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🛡️</div>
                    <div class="nav-card-title">Audit Trail & Security</div>
                </div>
                <div class="nav-card-desc">
                    Review activity logs, authentication records, data preservation operations and administrative updates.
                </div>
            </a>

            <a href="system_status.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🖥️</div>
                    <div class="nav-card-title">System Status & Health</div>
                </div>
                <div class="nav-card-desc">
                    Monitor PHP runtime, database connectivity, dataset storage directory accessibility and repository inventory status.
                </div>
            </a>

            <a href="roles.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🔑</div>
                    <div class="nav-card-title">Roles & Permissions</div>
                </div>
                <div class="nav-card-desc">
                    Inspect established system roles, role descriptions, user population distributions and permission matrices.
                </div>
            </a>

            <a href="settings.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">⚙️</div>
                    <div class="nav-card-title">Safe System Settings</div>
                </div>
                <div class="nav-card-desc">
                    Configure repository branding, default pagination limits, contact email addresses and maintenance notices.
                </div>
            </a>

            <a href="backups.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">💾</div>
                    <div class="nav-card-title">Backup & Disaster Recovery</div>
                </div>
                <div class="nav-card-desc">
                    Manage database backup snapshots, repository file redundancy policies, snapshot verification and disaster restore runbooks.
                </div>
            </a>

            <a href="interoperability.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🌐</div>
                    <div class="nav-card-title">Interoperability & Connectors</div>
                </div>
                <div class="nav-card-desc">
                    Configure institutional integrations for Campus SSO/SAML2, ORCID researcher sync, OAI-PMH harvesting and external repositories.
                </div>
            </a>

            <a href="../reports/index.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">📊</div>
                    <div class="nav-card-title">Reports & Analytics</div>
                </div>
                <div class="nav-card-desc">
                    Repository analytics hub, FAIR metadata indicators, access requests governance and tabular CSV exports.
                </div>
            </a>

            <a href="../projects/index.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">📁</div>
                    <div class="nav-card-title">Projects Governance</div>
                </div>
                <div class="nav-card-desc">
                    Oversight of faculty and departmental research projects, data types and lifecycle states.
                </div>
            </a>

            <a href="../search/index.php" class="admin-nav-card">
                <div class="nav-card-header">
                    <div class="nav-card-icon">🔍</div>
                    <div class="nav-card-title">Repository Discovery</div>
                </div>
                <div class="nav-card-desc">
                    Search and discover research datasets across faculties, subjects, licenses and file formats.
                </div>
            </a>

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
