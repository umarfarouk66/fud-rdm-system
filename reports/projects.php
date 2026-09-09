<?php
/**
 * Research Projects Analytics Report
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/reports_helpers.php';

// Require authenticated session with admin or librarian role
requireRole(['admin', 'librarian']);

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Date filtering
$datePreset  = trim($_GET['date_preset'] ?? 'all');
$customStart = trim($_GET['start_date'] ?? '');
$customEnd   = trim($_GET['end_date'] ?? '');
$dateFilter  = buildDateFilterClause($datePreset, $customStart, $customEnd, 'p.created_at');

// Scope
$scope = getReportingScope($userId, $systemRole);
$projWhere = $scope['project_where'] . " AND " . $dateFilter['clause'];
$bindings = array_merge($scope['project_params'], $dateFilter['params']);

// 1. Project Totals
$totals = [
    'total'     => 0,
    'active'    => 0,
    'completed' => 0,
    'suspended' => 0,
    'archived'  => 0,
    'datasets'  => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT p.id) AS total,
            COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) AS act,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) AS cmp,
            COUNT(DISTINCT CASE WHEN p.status = 'suspended' THEN p.id END) AS sus,
            COUNT(DISTINCT CASE WHEN p.status = 'archived' THEN p.id END) AS arc
        FROM research_projects p
        WHERE {$projWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totals['total']     = (int)$row['total'];
        $totals['active']    = (int)$row['act'];
        $totals['completed'] = (int)$row['cmp'];
        $totals['suspended'] = (int)$row['sus'];
        $totals['archived']  = (int)$row['arc'];
    }

    // Associated datasets count
    $dStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id) 
        FROM datasets d 
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE {$projWhere} AND d.status != 'deleted'
    ");
    $dStmt->execute($bindings);
    $totals['datasets'] = (int)$dStmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Projects Report Totals Error: " . $e->getMessage());
}

$avgDatasetsPerProject = ($totals['total'] > 0) ? round($totals['datasets'] / $totals['total'], 1) : 0.0;

// 2. Projects by Faculty
$facultyDist = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(p.faculty, ''), 'Unspecified Faculty') AS fac, COUNT(DISTINCT p.id) AS cnt
        FROM research_projects p
        WHERE {$projWhere}
        GROUP BY fac
        ORDER BY cnt DESC
        LIMIT 6
    ");
    $stmt->execute($bindings);
    $facultyDist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Faculty Dist Error: " . $e->getMessage());
}

// 3. DMP Approval Status across projects
$dmpStats = [
    'total'     => 0,
    'approved'  => 0,
    'submitted' => 0,
    'draft'     => 0,
    'rejected'  => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT dmp.id) AS total,
            COUNT(DISTINCT CASE WHEN dmp.status = 'approved' THEN dmp.id END) AS app,
            COUNT(DISTINCT CASE WHEN dmp.status = 'submitted' THEN dmp.id END) AS sub,
            COUNT(DISTINCT CASE WHEN dmp.status = 'draft' THEN dmp.id END) AS drf,
            COUNT(DISTINCT CASE WHEN dmp.status = 'rejected' THEN dmp.id END) AS rej
        FROM data_management_plans dmp
        INNER JOIN research_projects p ON dmp.project_id = p.id
        WHERE {$projWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $dmpStats['total']     = (int)$row['total'];
        $dmpStats['approved']  = (int)$row['app'];
        $dmpStats['submitted'] = (int)$row['sub'];
        $dmpStats['draft']     = (int)$row['drf'];
        $dmpStats['rejected']  = (int)$row['rej'];
    }
} catch (PDOException $e) {
    error_log("DMP Report Stats Error: " . $e->getMessage());
}

// 4. Monthly Project Registration Trend
$projectTrend = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(p.created_at, '%Y-%m') AS ym, COUNT(DISTINCT p.id) AS cnt
        FROM research_projects p
        WHERE {$projWhere}
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 12
    ");
    $stmt->execute($bindings);
    $projectTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Project Trend Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Projects Analytics — FUD RDM System</title>
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
                <a href="export.php?report=projects&format=print" target="_blank" class="btn-action" style="background: #1e3a8a; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    🖨️ Print / Save as PDF
                </a>
                <a href="export.php?report=projects&format=csv" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📊 CSV Data
                </a>
                <a href="export.php?report=projects&format=txt" class="btn-action" style="background: #f8fafc; color: #334155; border: 1px solid var(--border-color); padding: 0.45rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📄 Text
                </a>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                📁 Research Projects Analytics
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Lifecycle distribution, faculty engagement, dataset density and Data Management Plan compliance.
            </p>
        </div>

        <!-- Filter Bar -->
        <form action="projects.php" method="GET" class="filter-panel">
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <label for="date_preset" style="font-size: 0.85rem; font-weight: 700; color: var(--text-main);">Period:</label>
                <select id="date_preset" name="date_preset" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm);" onchange="this.form.submit();">
                    <option value="all" <?= ($datePreset === 'all') ? 'selected' : ''; ?>>All Time (Lifetime)</option>
                    <option value="today" <?= ($datePreset === 'today') ? 'selected' : ''; ?>>Today</option>
                    <option value="7days" <?= ($datePreset === '7days') ? 'selected' : ''; ?>>Last 7 Days</option>
                    <option value="30days" <?= ($datePreset === '30days') ? 'selected' : ''; ?>>Last 30 Days</option>
                    <option value="90days" <?= ($datePreset === '90days') ? 'selected' : ''; ?>>Last 90 Days</option>
                    <option value="thisyear" <?= ($datePreset === 'thisyear') ? 'selected' : ''; ?>>This Year</option>
                </select>
            </div>
            <div style="font-size: 0.85rem; color: var(--text-muted);">
                Avg Datasets per Project: <strong><?= $avgDatasetsPerProject; ?></strong>
            </div>
        </form>

        <!-- Metric Stat Cards -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">📁</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['total']; ?></div>
                    <div class="stat-label">Total Projects</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">⚡</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['active']; ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">✅</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['completed']; ?></div>
                    <div class="stat-label">Completed Projects</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-amber">📋</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $dmpStats['approved']; ?></div>
                    <div class="stat-label">Approved DMPs (<?= $dmpStats['total']; ?> Total)</div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="analytics-grid">
            <!-- Project Status Breakdown -->
            <div class="chart-box">
                <div class="chart-title">Project Lifecycle Status</div>
                <?php if ($totals['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No projects for period.</p>
                <?php else: ?>
                    <?= renderBarVisual('Active', $totals['active'], $totals['total'], '#059669'); ?>
                    <?= renderBarVisual('Completed', $totals['completed'], $totals['total'], '#2563eb'); ?>
                    <?= renderBarVisual('Suspended', $totals['suspended'], $totals['total'], '#d97706'); ?>
                    <?= renderBarVisual('Archived', $totals['archived'], $totals['total'], '#64748b'); ?>
                <?php endif; ?>
            </div>

            <!-- DMP Compliance Breakdown -->
            <div class="chart-box">
                <div class="chart-title">Data Management Plan (DMP) Progress</div>
                <?php if ($dmpStats['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No DMPs authored yet.</p>
                <?php else: ?>
                    <?= renderBarVisual('Approved DMPs', $dmpStats['approved'], $dmpStats['total'], '#059669'); ?>
                    <?= renderBarVisual('Submitted / In Review', $dmpStats['submitted'], $dmpStats['total'], '#d97706'); ?>
                    <?= renderBarVisual('Draft DMPs', $dmpStats['draft'], $dmpStats['total'], '#64748b'); ?>
                    <?= renderBarVisual('Revision Required', $dmpStats['rejected'], $dmpStats['total'], '#dc2626'); ?>
                <?php endif; ?>
            </div>

            <!-- Faculty Distribution -->
            <div class="chart-box">
                <div class="chart-title">Projects by Faculty</div>
                <?php if (empty($facultyDist)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No faculty metadata recorded.</p>
                <?php else: ?>
                    <?php foreach ($facultyDist as $f): ?>
                        <?= renderBarVisual($f['fac'], (int)$f['cnt'], $totals['total'], '#0284c7'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Registration Trend -->
            <div class="chart-box">
                <div class="chart-title">Project Registration Trend (Monthly)</div>
                <?php if (empty($projectTrend)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No temporal data available.</p>
                <?php else: ?>
                    <?php 
                    $maxProjectMonthly = max(array_column($projectTrend, 'cnt')) ?: 1;
                    foreach ($projectTrend as $p): ?>
                        <?= renderBarVisual(date('M Y', strtotime($p['ym'] . '-01')), (int)$p['cnt'], $maxProjectMonthly, '#10b981'); ?>
                    <?php endforeach; ?>
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
