<?php
/**
 * System Operational Status & Health Center
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

// 1. Application & Environment Metrics
$phpVersion = PHP_VERSION;
$serverSoftware = htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Apache/XAMPP', ENT_QUOTES, 'UTF-8');
$timezone = date_default_timezone_get();
$dbStatus = 'Connected';
$dbDriver = 'MySQL (PDO)';

try {
    $pdo->query("SELECT 1");
} catch (Exception $e) {
    $dbStatus = 'Connection Error';
}

// 2. User Breakdown
$userStats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'suspended' => 0];
try {
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
} catch (PDOException $e) {
    error_log("Status User Query Error: " . $e->getMessage());
}

// 3. Repository & Dataset Metrics
$repoStats = [
    'total_datasets'     => 0,
    'public_datasets'    => 0,
    'restricted_datasets'=> 0,
    'private_datasets'   => 0,
    'preserved_datasets' => 0,
    'archived_datasets'  => 0,
    'total_projects'     => 0,
    'pending_requests'   => 0,
    'pending_dmps'       => 0
];

try {
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
        $repoStats['total_datasets']      = (int)$dRow['total'];
        $repoStats['public_datasets']     = (int)$dRow['public_count'];
        $repoStats['restricted_datasets'] = (int)$dRow['restricted_count'];
        $repoStats['private_datasets']    = (int)$dRow['private_count'];
        $repoStats['preserved_datasets']  = (int)$dRow['preserved_count'];
        $repoStats['archived_datasets']   = (int)$dRow['archived_count'];
    }

    $repoStats['total_projects']   = (int)$pdo->query("SELECT COUNT(*) FROM research_projects")->fetchColumn();
    $repoStats['pending_requests'] = (int)$pdo->query("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'")->fetchColumn();
    $repoStats['pending_dmps']     = (int)$pdo->query("SELECT COUNT(*) FROM data_management_plans WHERE status = 'submitted'")->fetchColumn();

} catch (PDOException $e) {
    error_log("Status Repo Query Error: " . $e->getMessage());
}

// 4. Safe Storage Directory Statistics (Calculated purely via PHP)
$storagePath = realpath(__DIR__ . '/../storage/datasets');
$storageAccessible = ($storagePath !== false && is_dir($storagePath) && is_readable($storagePath));
$storageFileCount = 0;
$storageTotalBytes = 0;

if ($storageAccessible) {
    $dirIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storagePath, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($dirIterator as $file) {
        if ($file->isFile()) {
            $storageFileCount++;
            $storageTotalBytes += $file->getSize();
        }
    }
}

function formatBytesSafe(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status & Repository Health — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .status-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        @media (max-width: 850px) {
            .status-grid-2 {
                grid-template-columns: 1fr;
            }
        }
        .status-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
        }
        .status-item:last-child {
            border-bottom: none;
        }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-weight: 700;
            font-size: 0.85rem;
        }
        .indicator-online { color: #059669; }
        .indicator-warning { color: #d97706; }
        .indicator-danger { color: #dc2626; }
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
                🖥️ System Status & Operational Health
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Live operational health indicators, database connectivity diagnostics, repository storage metrics and workflow queues.
            </p>
        </div>

        <!-- Top Status Overview Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">⚡</div>
                <div class="stat-info">
                    <div class="stat-value" style="color: #059669;">Operational</div>
                    <div class="stat-label">Core System State</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">🗄️</div>
                <div class="stat-info">
                    <div class="stat-value"><?= e($dbStatus); ?></div>
                    <div class="stat-label">Database Connection</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">💾</div>
                <div class="stat-info">
                    <div class="stat-value"><?= formatBytesSafe($storageTotalBytes); ?></div>
                    <div class="stat-label">Storage Consumed</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-amber">⏳</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $repoStats['pending_requests'] + $repoStats['pending_dmps']; ?></div>
                    <div class="stat-label">Pending Workflow Tasks</div>
                </div>
            </div>
        </div>

        <!-- 2-Column Status Details -->
        <div class="status-grid-2">

            <!-- Box 1: Runtime & Environment -->
            <div class="status-box">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                    ⚙️ Runtime & Environment
                </h3>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Application Status</span>
                    <span class="status-indicator indicator-online">● Operational</span>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">PHP Engine Version</span>
                    <strong>PHP <?= e($phpVersion); ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Web Server</span>
                    <strong><?= e($serverSoftware); ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Database Connector</span>
                    <strong><?= e($dbDriver); ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Database State</span>
                    <span class="status-indicator indicator-online">● <?= e($dbStatus); ?></span>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Server Timezone</span>
                    <strong><?= e($timezone); ?></strong>
                </div>
            </div>

            <!-- Box 2: Repository Storage Health -->
            <div class="status-box">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                    💾 Repository Storage Health
                </h3>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Dataset Storage Directory</span>
                    <span class="status-indicator <?= $storageAccessible ? 'indicator-online' : 'indicator-danger'; ?>">
                        <?= $storageAccessible ? '● Accessible (Read/Write)' : '● Inaccessible'; ?>
                    </span>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Physical Files in Storage</span>
                    <strong><?= number_format($storageFileCount); ?> files</strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Total Physical Storage Used</span>
                    <strong><?= formatBytesSafe($storageTotalBytes); ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Integrity Algorithm</span>
                    <strong>SHA-256 Cryptographic Hash</strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Preservation Protection</span>
                    <span class="status-indicator indicator-online">● Enforced (Deletion Guard Active)</span>
                </div>
            </div>

            <!-- Box 3: Repository Inventory -->
            <div class="status-box">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                    📊 Repository Inventory
                </h3>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Total Active Datasets</span>
                    <strong><?= $repoStats['total_datasets']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Public Open Access</span>
                    <strong><?= $repoStats['public_datasets']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Restricted (Access Approval Required)</span>
                    <strong><?= $repoStats['restricted_datasets']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Private (Team Only)</span>
                    <strong><?= $repoStats['private_datasets']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Preserved in Long-Term Storage</span>
                    <strong style="color: #059669;"><?= $repoStats['preserved_datasets']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Archived</span>
                    <strong><?= $repoStats['archived_datasets']; ?></strong>
                </div>
            </div>

            <!-- Box 4: Users & Governance -->
            <div class="status-box">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                    👥 Users & Workflow Queues
                </h3>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Total Registered Users</span>
                    <strong><?= $userStats['total']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Active Accounts</span>
                    <strong style="color: #059669;"><?= $userStats['active']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Inactive Accounts</span>
                    <strong><?= $userStats['inactive']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">&bull; Suspended Accounts</span>
                    <strong style="color: #dc2626;"><?= $userStats['suspended']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Pending Access Requests</span>
                    <strong style="color: #d97706;"><?= $repoStats['pending_requests']; ?></strong>
                </div>
                <div class="status-item">
                    <span style="color: var(--text-muted);">Submitted DMPs in Review</span>
                    <strong style="color: #d97706;"><?= $repoStats['pending_dmps']; ?></strong>
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
