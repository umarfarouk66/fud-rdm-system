<?php
/**
 * Dataset Analytics & Metadata Quality Report
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/reports_helpers.php';

// Require authenticated session with admin or librarian role
requireRole(['admin', 'librarian']);

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Date filtering
$datePreset = trim($_GET['date_preset'] ?? 'all');
$customStart = trim($_GET['start_date'] ?? '');
$customEnd   = trim($_GET['end_date'] ?? '');
$dateFilter = buildDateFilterClause($datePreset, $customStart, $customEnd, 'd.created_at');

// Scope
$scope = getReportingScope($userId, $systemRole);
$dsWhere = $scope['dataset_where'] . " AND " . $dateFilter['clause'];
$bindings = array_merge($scope['dataset_params'], $dateFilter['params']);

// 1. Core Totals
$totals = [
    'total'      => 0,
    'public'     => 0,
    'restricted' => 0,
    'private'    => 0,
    'preserved'  => 0,
    'archived'   => 0,
    'awaiting'   => 0,
    'total_size' => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT d.id) AS total,
            COUNT(DISTINCT CASE WHEN d.access_level = 'public' THEN d.id END) AS pub,
            COUNT(DISTINCT CASE WHEN d.access_level = 'restricted' THEN d.id END) AS res,
            COUNT(DISTINCT CASE WHEN d.access_level = 'private' THEN d.id END) AS prv,
            COUNT(DISTINCT CASE WHEN d.status = 'preserved' THEN d.id END) AS prs,
            COUNT(DISTINCT CASE WHEN d.status = 'archived' THEN d.id END) AS arc,
            COUNT(DISTINCT CASE WHEN d.status NOT IN ('preserved', 'archived', 'deleted') THEN d.id END) AS awt,
            COALESCE(SUM(d.total_size), 0) AS total_size
        FROM datasets d
        WHERE {$dsWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totals['total']      = (int)$row['total'];
        $totals['public']     = (int)$row['pub'];
        $totals['restricted'] = (int)$row['res'];
        $totals['private']    = (int)$row['prv'];
        $totals['preserved']  = (int)$row['prs'];
        $totals['archived']   = (int)$row['arc'];
        $totals['awaiting']   = (int)$row['awt'];
        $totals['total_size'] = (int)$row['total_size'];
    }
} catch (PDOException $e) {
    error_log("Dataset Report Totals Error: " . $e->getMessage());
}

// 2. Metadata Quality Breakdown
$metaQuality = [
    'complete' => 0,
    'mostly'   => 0,
    'incomplete'=> 0,
    'no_meta'  => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT d.id, dm.*
        FROM datasets d
        LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
        WHERE {$dsWhere}
    ");
    $stmt->execute($bindings);
    $allDs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allDs as $item) {
        if (empty($item['creator']) && empty($item['subject_area'])) {
            $metaQuality['no_meta']++;
        } else {
            $comp = calculateMetadataCompleteness($item);
            if ($comp['percentage'] >= 80) {
                $metaQuality['complete']++;
            } elseif ($comp['percentage'] >= 50) {
                $metaQuality['mostly']++;
            } else {
                $metaQuality['incomplete']++;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Dataset Metadata Quality Query Error: " . $e->getMessage());
}

// 3. Distribution by File Format
$formatDist = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(dm.file_format, ''), 'Unspecified') AS fmt, COUNT(DISTINCT d.id) AS cnt
        FROM datasets d
        LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
        WHERE {$dsWhere}
        GROUP BY fmt
        ORDER BY cnt DESC
        LIMIT 6
    ");
    $stmt->execute($bindings);
    $formatDist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Format Dist Error: " . $e->getMessage());
}

// 4. Distribution by Subject Area
$subjectDist = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(dm.subject_area, ''), 'Unclassified') AS sbj, COUNT(DISTINCT d.id) AS cnt
        FROM datasets d
        LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
        WHERE {$dsWhere}
        GROUP BY sbj
        ORDER BY cnt DESC
        LIMIT 6
    ");
    $stmt->execute($bindings);
    $subjectDist = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Subject Dist Error: " . $e->getMessage());
}

// 5. Monthly Dataset Creation Trend
$monthlyTrend = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(d.created_at, '%Y-%m') AS ym, COUNT(DISTINCT d.id) AS cnt
        FROM datasets d
        WHERE {$dsWhere}
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 12
    ");
    $stmt->execute($bindings);
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Monthly Trend Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dataset Analytics & Metadata Quality — FUD RDM System</title>
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
                <a href="export.php?report=datasets&format=print" target="_blank" class="btn-action" style="background: #1e3a8a; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    🖨️ Print / Save as PDF
                </a>
                <a href="export.php?report=datasets&format=csv" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📊 CSV Data
                </a>
                <a href="export.php?report=datasets&format=txt" class="btn-action" style="background: #f8fafc; color: #334155; border: 1px solid var(--border-color); padding: 0.45rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📄 Text
                </a>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                📊 Dataset Analytics & Metadata Quality
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Detailed distributions of research datasets by access classification, FAIR metadata completeness, formats and subjects.
            </p>
        </div>

        <!-- Filter Bar -->
        <form action="datasets.php" method="GET" class="filter-panel">
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
                Total Storage Volume: <strong><?= formatFileSize($totals['total_size']); ?></strong>
            </div>
        </form>

        <!-- Metric Stat Cards -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">📊</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['total']; ?></div>
                    <div class="stat-label">Total Datasets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">🔓</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['public']; ?></div>
                    <div class="stat-label">Public Access</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-amber">🔒</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['restricted']; ?></div>
                    <div class="stat-label">Restricted Access</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">🛡️</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['preserved']; ?></div>
                    <div class="stat-label">Preserved (Locked)</div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="analytics-grid">
            <!-- Access Level Breakdown -->
            <div class="chart-box">
                <div class="chart-title">Access Level Distribution</div>
                <?php if ($totals['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No data for period.</p>
                <?php else: ?>
                    <?= renderBarVisual('Public Access', $totals['public'], $totals['total'], '#059669'); ?>
                    <?= renderBarVisual('Restricted Access', $totals['restricted'], $totals['total'], '#d97706'); ?>
                    <?= renderBarVisual('Private Access', $totals['private'], $totals['total'], '#64748b'); ?>
                <?php endif; ?>
            </div>

            <!-- Lifecycle Status Breakdown -->
            <div class="chart-box">
                <div class="chart-title">Lifecycle & Preservation Status</div>
                <?php if ($totals['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No data for period.</p>
                <?php else: ?>
                    <?= renderBarVisual('Preserved (Cryptographic Lock)', $totals['preserved'], $totals['total'], '#059669'); ?>
                    <?= renderBarVisual('Awaiting Preservation', $totals['awaiting'], $totals['total'], '#2563eb'); ?>
                    <?= renderBarVisual('Archived', $totals['archived'], $totals['total'], '#475569'); ?>
                <?php endif; ?>
            </div>

            <!-- Metadata Quality Completeness -->
            <div class="chart-box">
                <div class="chart-title">FAIR Metadata Quality Distribution</div>
                <?php if ($totals['total'] === 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No data for period.</p>
                <?php else: ?>
                    <?= renderBarVisual('Complete (≥80%)', $metaQuality['complete'], $totals['total'], '#059669'); ?>
                    <?= renderBarVisual('Mostly Complete (50–79%)', $metaQuality['mostly'], $totals['total'], '#d97706'); ?>
                    <?= renderBarVisual('Incomplete (<50%)', $metaQuality['incomplete'], $totals['total'], '#dc2626'); ?>
                    <?= renderBarVisual('No Metadata Added', $metaQuality['no_meta'], $totals['total'], '#94a3b8'); ?>
                <?php endif; ?>
            </div>

            <!-- Top File Formats -->
            <div class="chart-box">
                <div class="chart-title">Top File Formats</div>
                <?php if (empty($formatDist)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No format metadata recorded.</p>
                <?php else: ?>
                    <?php foreach ($formatDist as $f): ?>
                        <?= renderBarVisual($f['fmt'], (int)$f['cnt'], $totals['total'], '#7c3aed'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Top Subject Domains -->
            <div class="chart-box">
                <div class="chart-title">Top Subject Domains</div>
                <?php if (empty($subjectDist)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No subject metadata recorded.</p>
                <?php else: ?>
                    <?php foreach ($subjectDist as $s): ?>
                        <?= renderBarVisual($s['sbj'], (int)$s['cnt'], $totals['total'], '#0284c7'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Deposition Trend -->
            <div class="chart-box">
                <div class="chart-title">Dataset Deposition Trend (Monthly)</div>
                <?php if (empty($monthlyTrend)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No temporal data available.</p>
                <?php else: ?>
                    <?php 
                    $maxMonthly = max(array_column($monthlyTrend, 'cnt')) ?: 1;
                    foreach ($monthlyTrend as $m): ?>
                        <?= renderBarVisual(date('M Y', strtotime($m['ym'] . '-01')), (int)$m['cnt'], $maxMonthly, '#2563eb'); ?>
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
