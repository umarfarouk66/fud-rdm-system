<?php
/**
 * Institutional Security & Audit Logs Viewer
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

// Filter & Search Parameters
$search      = trim($_GET['q'] ?? '');
$actionType  = trim($_GET['action'] ?? '');
$userFilter  = (int)($_GET['user_id'] ?? 0);
$entityFilter = trim($_GET['entity_type'] ?? '');
$startDate   = trim($_GET['start_date'] ?? '');
$endDate     = trim($_GET['end_date'] ?? '');

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(10, min(50, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// Build Prepared Query
$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(a.description LIKE :q OR a.action LIKE :q OR a.entity_type LIKE :q OR a.ip_address LIKE :q)";
    $params[':q'] = "%{$search}%";
}

if ($actionType !== '') {
    $where[] = "a.action = :action";
    $params[':action'] = $actionType;
}

if ($userFilter > 0) {
    $where[] = "a.user_id = :uid";
    $params[':uid'] = $userFilter;
}

if ($entityFilter !== '') {
    $where[] = "a.entity_type = :entity";
    $params[':entity'] = $entityFilter;
}

if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $where[] = "a.created_at >= :start_date";
    $params[':start_date'] = "{$startDate} 00:00:00";
}

if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $where[] = "a.created_at <= :end_date";
    $params[':end_date'] = "{$endDate} 23:59:59";
}

$whereClause = implode(" AND ", $where);

// Count Total Matching Records
$countStmt = $pdo->prepare("
    SELECT COUNT(a.id)
    FROM audit_logs a
    WHERE {$whereClause}
");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $limit));

// Fetch Paginated Logs
$logStmt = $pdo->prepare("
    SELECT 
        a.id,
        a.user_id,
        a.action,
        a.entity_type,
        a.entity_id,
        a.description,
        a.ip_address,
        a.created_at,
        u.first_name,
        u.last_name,
        u.email,
        r.name AS role_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE {$whereClause}
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$logStmt->execute($params);
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Distinct Actions and Entity Types for dropdowns
$actionsList = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$entityList  = $pdo->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs & Security Trail — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            flex: 1;
            min-width: 150px;
        }
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .filter-group input, .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            background: #ffffff;
        }
        .data-table-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }
        .data-table th {
            background: #f8fafc;
            padding: 0.85rem 1rem;
            font-weight: 700;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .data-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: top;
        }
        .data-table tr:hover {
            background: #f8fafc;
        }
        .action-tag {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
            background: #e0e7ff;
            color: #3730a3;
            display: inline-block;
            white-space: nowrap;
        }
        .entity-tag {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            display: inline-block;
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
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
            <div style="font-size: 0.9rem; color: var(--text-muted);">
                Showing <strong><?= count($logs); ?></strong> of <strong><?= $totalLogs; ?></strong> audit events
            </div>
        </div>

        <!-- Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🛡️ System Audit Trail & Security Logs
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Immutable institutional record of security events, administrative updates, dataset access actions and data preservation records.
            </p>
        </div>

        <!-- Filter Form -->
        <form method="GET" action="audit_logs.php" class="filter-panel">
            <div class="filter-group" style="flex: 2; min-width: 200px;">
                <label for="q">Keyword Search</label>
                <input type="text" id="q" name="q" value="<?= e($search); ?>" placeholder="Search narrative, action, IP...">
            </div>

            <div class="filter-group">
                <label for="action">Action Type</label>
                <select id="action" name="action">
                    <option value="">All Actions</option>
                    <?php foreach ($actionsList as $act): ?>
                        <option value="<?= e($act); ?>" <?= ($actionType === $act) ? 'selected' : ''; ?>>
                            <?= e($act); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="entity_type">Entity Target</label>
                <select id="entity_type" name="entity_type">
                    <option value="">All Entities</option>
                    <?php foreach ($entityList as $ent): ?>
                        <option value="<?= e($ent); ?>" <?= ($entityFilter === $ent) ? 'selected' : ''; ?>>
                            <?= e($ent); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= e($startDate); ?>">
            </div>

            <div class="filter-group">
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= e($endDate); ?>">
            </div>

            <div>
                <button type="submit" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.55rem 1.25rem; font-weight: 600; border-radius: var(--radius-sm); border: none; cursor: pointer;">
                    Filter Logs
                </button>
                <?php if ($search !== '' || $actionType !== '' || $entityFilter !== '' || $startDate !== '' || $endDate !== ''): ?>
                    <a href="audit_logs.php" style="margin-left: 0.5rem; font-size: 0.85rem; color: var(--text-muted); text-decoration: underline;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Audit Table -->
        <div class="data-table-card">
            <?php if (empty($logs)): ?>
                <div style="padding: 3rem 1.5rem; text-align: center; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🔍</div>
                    <h3 style="font-size: 1.15rem; color: var(--primary-color); margin-bottom: 0.25rem;">No Audit Records Found</h3>
                    <p style="font-size: 0.9rem;">No security audit events matched your search criteria.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Entity Target</th>
                            <th>Description / Narrative</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $actorName = !empty($log['first_name']) ? trim($log['first_name'] . ' ' . $log['last_name']) : 'System / Guest';
                            $actorRole = $log['role_name'] ?? 'system';
                        ?>
                            <tr>
                                <td style="white-space: nowrap; color: var(--text-muted); font-size: 0.8rem;">
                                    <?= date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php if (!empty($log['user_id'])): ?>
                                        <a href="user_view.php?id=<?= (int)$log['user_id']; ?>" style="color: var(--primary-color); font-weight: 700; text-decoration: none;">
                                            <?= e($actorName); ?>
                                        </a>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">#<?= (int)$log['user_id']; ?> (<?= e(ucfirst($actorRole)); ?>)</div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic;">System Event</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-tag">
                                        <?= e($log['action']); ?>
                                    </span>
                                </td>
                                <td style="white-space: nowrap;">
                                    <?php if (!empty($log['entity_type'])): ?>
                                        <span class="entity-tag">
                                            <?= e($log['entity_type']); ?> #<?= (int)$log['entity_id']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 450px;">
                                    <?= e($log['description']); ?>
                                </td>
                                <td style="white-space: nowrap; font-family: monospace; font-size: 0.8rem; color: var(--text-muted);">
                                    <?= e($log['ip_address'] ?: '127.0.0.1'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem;">
                <?php for ($p = 1; $p <= $totalPages; $p++): 
                    $pageParams = array_merge($_GET, ['page' => $p]);
                ?>
                    <a href="audit_logs.php?<?= http_build_query($pageParams); ?>" style="padding: 0.4rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 600; text-decoration: none; <?= ($p === $page) ? 'background: var(--primary-color); color: #ffffff;' : 'background: #ffffff; color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
                        <?= $p; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

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
