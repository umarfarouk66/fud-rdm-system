<?php
/**
 * Access Requests & Governance Analytics Report
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../access/access_helpers.php';
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
$dateFilter  = buildDateFilterClause($datePreset, $customStart, $customEnd, 'ar.created_at');

// Scope
$scope = getReportingScope($userId, $systemRole);
$accWhere = $scope['access_req_where'] . " AND " . $dateFilter['clause'];
$bindings = array_merge($scope['access_req_params'], $dateFilter['params']);

// 1. Core Access Request Totals
$totals = [
    'total'    => 0,
    'pending'  => 0,
    'approved' => 0,
    'rejected' => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ar.id) AS total,
            COUNT(DISTINCT CASE WHEN ar.status = 'pending' THEN ar.id END) AS pending,
            COUNT(DISTINCT CASE WHEN ar.status = 'approved' THEN ar.id END) AS approved,
            COUNT(DISTINCT CASE WHEN ar.status = 'rejected' THEN ar.id END) AS rejected
        FROM access_requests ar
        WHERE {$accWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totals['total']    = (int)$row['total'];
        $totals['pending']  = (int)$row['pending'];
        $totals['approved'] = (int)$row['approved'];
        $totals['rejected'] = (int)$row['rejected'];
    }
} catch (PDOException $e) {
    error_log("Access Report Totals Error: " . $e->getMessage());
}

$reviewedCount = $totals['approved'] + $totals['rejected'];
$approvalRate  = calculateSafeRate($totals['approved'], $reviewedCount);
$rejectionRate = calculateSafeRate($totals['rejected'], $reviewedCount);

// 2. Top Requested Restricted Datasets
$topDatasets = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.id AS dataset_id, d.title AS dataset_title, COUNT(ar.id) AS req_count
        FROM access_requests ar
        INNER JOIN datasets d ON ar.dataset_id = d.id
        WHERE {$accWhere}
        GROUP BY d.id
        ORDER BY req_count DESC
        LIMIT 6
    ");
    $stmt->execute($bindings);
    $topDatasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Top Datasets Error: " . $e->getMessage());
}

// 3. Monthly Access Request Trends
$requestTrend = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(ar.created_at, '%Y-%m') AS ym, COUNT(DISTINCT ar.id) AS cnt
        FROM access_requests ar
        WHERE {$accWhere}
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 12
    ");
    $stmt->execute($bindings);
    $requestTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Request Trend Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Requests & Governance Analytics — FUD RDM System</title>
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
                <a href="export.php?report=access&format=print" target="_blank" class="btn-action" style="background: #1e3a8a; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    🖨️ Print / Save as PDF
                </a>
                <a href="export.php?report=access&format=csv" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📊 CSV Data
                </a>
                <a href="export.php?report=access&format=txt" class="btn-action" style="background: #f8fafc; color: #334155; border: 1px solid var(--border-color); padding: 0.45rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📄 Text
                </a>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🔑 Access Requests & Governance Analytics
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Audit access governance metrics, compliance approval rates and evaluation patterns for restricted datasets.
            </p>
        </div>

        <!-- Filter Bar -->
        <form action="access.php" method="GET" class="filter-panel">
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
                Reviewed Decisions: <strong><?= $reviewedCount; ?></strong>
            </div>
        </form>

        <!-- Metric Stat Cards -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">🔑</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['total']; ?></div>
                    <div class="stat-label">Total Requests</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-amber">⏳</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['pending']; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">✅</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['approved']; ?></div>
                    <div class="stat-label">Approved (<?= $approvalRate; ?>%)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">❌</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['rejected']; ?></div>
                    <div class="stat-label">Declined (<?= $rejectionRate; ?>%)</div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="analytics-grid">
            <!-- Access Request Status Distribution -->
            <div class="chart-box">
                <div class="chart-title">Access Request Status Distribution</div>
                <?php if ($totals['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No requests for period.</p>
                <?php else: ?>
                    <?= renderBarVisual('Approved Access', $totals['approved'], $totals['total'], '#059669'); ?>
                    <?= renderBarVisual('Pending Evaluation', $totals['pending'], $totals['total'], '#d97706'); ?>
                    <?= renderBarVisual('Declined Requests', $totals['rejected'], $totals['total'], '#dc2626'); ?>
                <?php endif; ?>
            </div>

            <!-- Top Requested Restricted Datasets -->
            <div class="chart-box">
                <div class="chart-title">Top Requested Restricted Datasets</div>
                <?php if (empty($topDatasets)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No dataset requests recorded.</p>
                <?php else: ?>
                    <?php 
                    $maxReq = max(array_column($topDatasets, 'req_count')) ?: 1;
                    foreach ($topDatasets as $td): ?>
                        <?= renderBarVisual($td['dataset_title'], (int)$td['req_count'], $maxReq, '#d97706'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Request Trends -->
            <div class="chart-box" style="grid-column: 1 / -1;">
                <div class="chart-title">Access Request Submission Trends (Monthly)</div>
                <?php if (empty($requestTrend)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No temporal data available.</p>
                <?php else: ?>
                    <?php 
                    $maxMonthlyReq = max(array_column($requestTrend, 'cnt')) ?: 1;
                    foreach ($requestTrend as $rt): ?>
                        <?= renderBarVisual(date('M Y', strtotime($rt['ym'] . '-01')), (int)$rt['cnt'], $maxMonthlyReq, '#2563eb'); ?>
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
