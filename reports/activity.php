<?php
/**
 * Repository Activity & Access Logs Report
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/reports_helpers.php';

// Require authenticated session with admin or librarian role
requireRole(['admin', 'librarian']);

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Date filtering
$datePreset  = trim($_GET['date_preset'] ?? '30days');
$customStart = trim($_GET['start_date'] ?? '');
$customEnd   = trim($_GET['end_date'] ?? '');
$dateFilter  = buildDateFilterClause($datePreset, $customStart, $customEnd, 'dal.created_at');

// Scope
$scope = getReportingScope($userId, $systemRole);
$dsWhere = $scope['dataset_where'];
$actWhere = $dateFilter['clause'];
$bindings = $dateFilter['params'];

if (!in_array($systemRole, ['admin', 'librarian'], true)) {
    $actWhere .= " AND (d.owner_id = :act_uid1 OR d.project_id IN (SELECT project_id FROM project_members WHERE user_id = :act_uid2))";
    $bindings[':act_uid1'] = $userId;
    $bindings[':act_uid2'] = $userId;
}

// 1. Activity Totals
$totals = [
    'downloads' => 0,
    'views'     => 0,
    'total_act' => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN dal.action = 'download' THEN 1 END) AS dl,
            COUNT(CASE WHEN dal.action = 'view' THEN 1 END) AS vw,
            COUNT(dal.id) AS total
        FROM dataset_access_logs dal
        INNER JOIN datasets d ON dal.dataset_id = d.id
        WHERE {$actWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totals['downloads'] = (int)$row['dl'];
        $totals['views']     = (int)$row['vw'];
        $totals['total_act'] = (int)$row['total'];
    }
} catch (PDOException $e) {
    error_log("Activity Report Totals Error: " . $e->getMessage());
}

// 2. Most Accessed Datasets (Top Downloaded)
$mostAccessed = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.access_level,
            p.project_code,
            COUNT(CASE WHEN dal.action = 'download' THEN 1 END) AS dl_count,
            COUNT(CASE WHEN dal.action = 'view' THEN 1 END) AS vw_count
        FROM dataset_access_logs dal
        INNER JOIN datasets d ON dal.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE {$actWhere}
        GROUP BY d.id
        ORDER BY dl_count DESC, vw_count DESC
        LIMIT 6
    ");
    $stmt->execute($bindings);
    $mostAccessed = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Most Accessed Error: " . $e->getMessage());
}

// 3. Recent Activity Stream
$recentLogs = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            dal.action,
            dal.created_at,
            d.id AS dataset_id,
            d.title AS dataset_title,
            u.first_name,
            u.last_name
        FROM dataset_access_logs dal
        INNER JOIN datasets d ON dal.dataset_id = d.id
        LEFT JOIN users u ON dal.user_id = u.id
        WHERE {$actWhere}
        ORDER BY dal.created_at DESC
        LIMIT 10
    ");
    $stmt->execute($bindings);
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Recent Logs Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repository Activity & Access Logs — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .chart-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            box-shadow: var(--shadow-sm);
        }
        .chart-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.25rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .activity-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
        }
        .activity-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .activity-table tr:last-child td {
            border-bottom: none;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="index.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                </div>
                <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Top Breadcrumbs -->
        <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Reporting Hub
            </a>
            <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                <a href="export.php?report=activity&format=print" target="_blank" class="btn-action" style="background: #1e3a8a; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    🖨️ Print / Save as PDF
                </a>
                <a href="export.php?report=activity&format=csv" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📊 CSV Data
                </a>
                <a href="export.php?report=activity&format=txt" class="btn-action" style="background: #f8fafc; color: #334155; border: 1px solid var(--border-color); padding: 0.45rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📄 Text
                </a>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                📈 Repository Activity & Access Logs
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Live traffic metrics for research dataset downloads, page views and access patterns.
            </p>
        </div>

        <!-- Filter Bar -->
        <form action="activity.php" method="GET" class="filter-panel">
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <label for="date_preset" style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">Period:</label>
                <select id="date_preset" name="date_preset" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm);" onchange="this.form.submit();">
                    <option value="today" <?= ($datePreset === 'today') ? 'selected' : ''; ?>>Today</option>
                    <option value="7days" <?= ($datePreset === '7days') ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="30days" <?= ($datePreset === '30days') ? 'selected' : ''; ?>>Last 30 Days (Default)</option>
                    <option value="90days" <?= ($datePreset === '90days') ? 'selected' : ''; ?>>Last 90 Days</option>
                    <option value="thisyear" <?= ($datePreset === 'thisyear') ? 'selected' : ''; ?>>This Year</option>
                    <option value="all" <?= ($datePreset === 'all') ? 'selected' : ''; ?>>All Time (Lifetime)</option>
                </select>
            </div>
            <div style="font-size: 0.85rem; color: var(--text-muted);">
                Total Events in Period: <strong><?= $totals['total_act']; ?></strong>
            </div>
        </form>

        <!-- Metric Stat Cards -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">⬇️</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['downloads']; ?></div>
                    <div class="stat-label">File Downloads</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">👁️</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['views']; ?></div>
                    <div class="stat-label">Dataset Views</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">⚡</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['total_act']; ?></div>
                    <div class="stat-label">Total Access Events</div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="analytics-grid">
            <!-- Most Downloaded Datasets -->
            <div class="chart-box">
                <div class="chart-title">Most Downloaded Datasets</div>
                <?php if (empty($mostAccessed)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No download activity in this period.</p>
                <?php else: ?>
                    <?php 
                    $maxDl = max(array_column($mostAccessed, 'dl_count')) ?: 1;
                    foreach ($mostAccessed as $ma): ?>
                        <?= renderBarVisual($ma['dataset_title'] . ' (' . $ma['project_code'] . ')', (int)$ma['dl_count'], $maxDl, '#059669'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Access Activity Type Ratio -->
            <div class="chart-box">
                <div class="chart-title">Activity Ratio (Views vs Downloads)</div>
                <?php if ($totals['total_act'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No activity in this period.</p>
                <?php else: ?>
                    <?= renderBarVisual('File Downloads', $totals['downloads'], $totals['total_act'], '#059669'); ?>
                    <?= renderBarVisual('Dataset Page Views', $totals['views'], $totals['total_act'], '#2563eb'); ?>
                <?php endif; ?>
            </div>

            <!-- Recent Activity Table -->
            <div class="chart-box" style="grid-column: 1 / -1;">
                <div class="chart-title">Recent Access Event Stream</div>
                <?php if (empty($recentLogs)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No activity recorded in selected period.</p>
                <?php else: ?>
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Dataset</th>
                                <th>User</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td>
                                        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; padding: 0.2rem 0.55rem; border-radius: 9999px; background: <?= $log['action'] === 'download' ? '#d1fae5; color: #065f46;' : '#eff6ff; color: #1e40af;'; ?>">
                                            <?= e($log['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="../datasets/view.php?id=<?= (int)$log['dataset_id']; ?>" style="color: var(--text-main); font-weight: 600; text-decoration: none;">
                                            <?= e($log['dataset_title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?= e(!empty($log['first_name']) ? $log['first_name'] . ' ' . $log['last_name'] : 'Authenticated User'); ?>
                                    </td>
                                    <td>
                                        <?= date('M d, Y H:i:s', strtotime($log['created_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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
