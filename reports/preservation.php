<?php
/**
 * Digital Preservation & Archival Analytics Report
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
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
$dateFilter  = buildDateFilterClause($datePreset, $customStart, $customEnd, 'pr.performed_at');

// Scope
$scope = getReportingScope($userId, $systemRole);
$dsWhere = $scope['dataset_where'];
$presWhere = "d.status != 'deleted' AND " . $dateFilter['clause'];
$bindings = $dateFilter['params'];

if (!in_array($systemRole, ['admin', 'librarian'], true)) {
    $presWhere .= " AND (d.owner_id = :p_uid1 OR d.project_id IN (SELECT project_id FROM project_members WHERE user_id = :p_uid2))";
    $bindings[':p_uid1'] = $userId;
    $bindings[':p_uid2'] = $userId;
}

// 1. Core Preservation Totals
$totals = [
    'total_records' => 0,
    'preserved_ds'  => 0,
    'archived_ds'   => 0,
    'awaiting_ds'   => 0
];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT pr.id) AS total_records,
            COUNT(DISTINCT CASE WHEN pr.action = 'preserve' THEN pr.dataset_id END) AS prs_ds,
            COUNT(DISTINCT CASE WHEN pr.action = 'archive' THEN pr.dataset_id END) AS arc_ds
        FROM preservation_records pr
        INNER JOIN datasets d ON pr.dataset_id = d.id
        WHERE {$presWhere}
    ");
    $stmt->execute($bindings);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $totals['total_records'] = (int)$row['total_records'];
        $totals['preserved_ds']  = (int)$row['prs_ds'];
        $totals['archived_ds']   = (int)$row['arc_ds'];
    }

    // Datasets awaiting preservation
    $awtStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT d.id) 
        FROM datasets d 
        WHERE {$dsWhere} AND d.status NOT IN ('preserved', 'archived', 'deleted')
    ");
    $awtStmt->execute($scope['dataset_params']);
    $totals['awaiting_ds'] = (int)$awtStmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Preservation Report Totals Error: " . $e->getMessage());
}

// 2. Storage Tier Allocation
$storageTiers = [];
try {
    $stmt = $pdo->prepare("
        SELECT pr.notes
        FROM preservation_records pr
        INNER JOIN datasets d ON pr.dataset_id = d.id
        WHERE {$presWhere}
    ");
    $stmt->execute($bindings);
    $allNotes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $tierCounts = [];
    foreach ($allNotes as $rawNotes) {
        $meta = parsePreservationNotes($rawNotes);
        $loc = $meta['location'] ?? 'Institutional Repository';
        $tierCounts[$loc] = ($tierCounts[$loc] ?? 0) + 1;
    }
    arsort($tierCounts);
    $storageTiers = $tierCounts;
} catch (PDOException $e) {
    error_log("Storage Tier Query Error: " . $e->getMessage());
}

// 3. Monthly Preservation Trend
$presTrend = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(pr.performed_at, '%Y-%m') AS ym, COUNT(DISTINCT pr.id) AS cnt
        FROM preservation_records pr
        INNER JOIN datasets d ON pr.dataset_id = d.id
        WHERE {$presWhere}
        GROUP BY ym
        ORDER BY ym ASC
        LIMIT 12
    ");
    $stmt->execute($bindings);
    $presTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Preservation Trend Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preservation & Archival Analytics — FUD RDM System</title>
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
                <a href="export.php?report=preservation&format=print" target="_blank" class="btn-action" style="background: #1e3a8a; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    🖨️ Print / Save as PDF
                </a>
                <a href="export.php?report=preservation&format=csv" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📊 CSV Data
                </a>
                <a href="export.php?report=preservation&format=txt" class="btn-action" style="background: #f8fafc; color: #334155; border: 1px solid var(--border-color); padding: 0.45rem 0.75rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 700; text-decoration: none;">
                    📄 Text
                </a>
            </div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🏛️ Preservation & Archival Pipeline Analytics
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Audit digital preservation records, cryptographic SHA-256 integrity assurances and storage tier distributions.
            </p>
        </div>

        <!-- Filter Bar -->
        <form action="preservation.php" method="GET" class="filter-panel">
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
                Preservation Executions: <strong><?= $totals['total_records']; ?></strong>
            </div>
        </form>

        <!-- Metric Stat Cards -->
        <div class="stats-grid" style="margin-bottom: 2rem;">
            <div class="stat-card">
                <div class="stat-icon-box icon-emerald">🛡️</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['preserved_ds']; ?></div>
                    <div class="stat-label">Preserved Datasets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-purple">📦</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['archived_ds']; ?></div>
                    <div class="stat-label">Archived Datasets</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-amber">⏳</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['awaiting_ds']; ?></div>
                    <div class="stat-label">Awaiting Preservation</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-box icon-blue">📜</div>
                <div class="stat-info">
                    <div class="stat-value"><?= $totals['total_records']; ?></div>
                    <div class="stat-label">Preservation Actions</div>
                </div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="analytics-grid">
            <!-- Storage Tier Distribution -->
            <div class="chart-box">
                <div class="chart-title">Storage Tier Allocation</div>
                <?php if (empty($storageTiers)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No preservation storage data.</p>
                <?php else: ?>
                    <?php 
                    $maxTier = max($storageTiers) ?: 1;
                    foreach ($storageTiers as $tName => $tCnt): ?>
                        <?= renderBarVisual($tName, (int)$tCnt, $maxTier, '#059669'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Preservation Timeline Trend -->
            <div class="chart-box">
                <div class="chart-title">Preservation Activity Trend (Monthly)</div>
                <?php if (empty($presTrend)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No temporal data available.</p>
                <?php else: ?>
                    <?php 
                    $maxPresMonthly = max(array_column($presTrend, 'cnt')) ?: 1;
                    foreach ($presTrend as $pt): ?>
                        <?= renderBarVisual(date('M Y', strtotime($pt['ym'] . '-01')), (int)$pt['cnt'], $maxPresMonthly, '#2563eb'); ?>
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
