<?php
/**
 * Data Preservation & Archiving Directory
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/preservation_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Enforce librarian / admin authorization
if (!canManagePreservation($systemRole)) {
    $_SESSION['access_error'] = 'Access denied: Dataset preservation and archiving is restricted to librarians and system administrators.';
    header('Location: ../researcher/dashboard.php');
    exit;
}

// Flash notifications
$successMsg = $_SESSION['preservation_success'] ?? null;
$errorMsg   = $_SESSION['preservation_error'] ?? null;
unset($_SESSION['preservation_success'], $_SESSION['preservation_error']);

// 1. Fetch Preserved / Archived Datasets
$preservedList = [];
try {
    $stmt = $pdo->query("
        SELECT 
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.status AS dataset_status,
            d.access_level,
            d.current_version,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            v.file_name,
            v.file_size,
            v.mime_type,
            v.checksum,
            pr.id AS preservation_id,
            pr.action AS preservation_action,
            pr.notes AS preservation_notes,
            pr.performed_at,
            u.first_name AS librarian_first_name,
            u.last_name AS librarian_last_name
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        LEFT JOIN dataset_versions v ON d.id = v.dataset_id AND d.current_version = v.version_number
        INNER JOIN preservation_records pr ON d.id = pr.dataset_id AND pr.id = (
            SELECT MAX(id) FROM preservation_records WHERE dataset_id = d.id
        )
        INNER JOIN users u ON pr.performed_by = u.id
        WHERE d.status IN ('preserved', 'archived')
        ORDER BY pr.performed_at DESC
    ");
    $preservedList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Preserved List Query Error: " . $e->getMessage());
}

// 2. Fetch Datasets Awaiting Preservation
$awaitingList = [];
try {
    $stmt = $pdo->query("
        SELECT 
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.status AS dataset_status,
            d.access_level,
            d.current_version,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            v.id AS version_id,
            v.file_name,
            v.file_size,
            v.mime_type,
            v.checksum,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON d.owner_id = u.id
        LEFT JOIN dataset_versions v ON d.id = v.dataset_id AND d.current_version = v.version_number
        WHERE d.status NOT IN ('preserved', 'archived', 'deleted')
          AND v.id IS NOT NULL
        ORDER BY d.created_at DESC
    ");
    $awaitingList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Awaiting Preservation Query Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Preservation & Archiving — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title h1 {
            font-size: 1.75rem;
            color: var(--primary-color);
            font-weight: 700;
        }
        .page-title p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 2.5rem;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .section-header-bar {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .data-table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .data-table tr:last-child td {
            border-bottom: none;
        }
        .data-table tr:hover td {
            background-color: #f8fafc;
        }
        .project-code-badge {
            font-family: monospace;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
        }
        .checksum-cell {
            font-family: monospace;
            font-size: 0.75rem;
            color: #475569;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.65rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            margin-right: 0.35rem;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            background: #ffffff;
            transition: all 0.15s ease;
        }
        .btn-action:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background-color: var(--primary-light);
        }
        .btn-action-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-action-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-action-success {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .btn-action-success:hover {
            background-color: #047857;
            color: #ffffff;
        }
        .btn-action-warning {
            background-color: #d97706;
            color: #ffffff;
            border-color: #d97706;
        }
        .btn-action-warning:hover {
            background-color: #b45309;
            color: #ffffff;
        }
        .empty-placeholder {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.95rem;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <header class="dash-navbar">
        <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="dash-brand">
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

        <!-- Flash Notifications -->
        <?php if (!empty($successMsg)): ?>
            <div class="dash-alert dash-alert-success">
                <span><?= e($successMsg); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="dash-alert dash-alert-danger">
                <span><?= e($errorMsg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>Data Preservation & Archiving Management</h1>
                <p>Verify cryptographic checksums, execute long-term preservation records and maintain institutional research archives</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="../datasets/index.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    🔍 Browse All Datasets
                </a>
            </div>
        </div>

        <!-- Section 1: Preserved & Archived Datasets -->
        <div class="section-card">
            <div class="section-header-bar">
                <h2 class="section-title">
                    🏛️ Preserved & Archived Research Datasets (<?= count($preservedList); ?>)
                </h2>
            </div>

            <?php if (empty($preservedList)): ?>
                <div class="empty-placeholder">
                    No preservation records have been created yet.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Dataset</th>
                                <th>Project</th>
                                <th>Version</th>
                                <th>Location & Format</th>
                                <th>SHA-256 Checksum</th>
                                <th>Status</th>
                                <th>Preserved Date</th>
                                <th>Preserved By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preservedList as $p): 
                                $meta = parsePreservationNotes($p['preservation_notes']);
                            ?>
                                <tr>
                                    <td>
                                        <a href="../datasets/view.php?id=<?= (int)$p['dataset_id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                            <?= e($p['dataset_title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="project-code-badge"><?= e($p['project_code']); ?></span>
                                    </td>
                                    <td>
                                        <strong>v<?= (int)$p['current_version']; ?></strong>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 0.85rem; color: #1e40af;">
                                            <?= e($meta['location'] ?? 'Institutional Repository'); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= formatFileSize((int)$p['file_size']); ?> &bull; <?= e($p['mime_type']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="checksum-cell" title="<?= e($p['checksum']); ?>">
                                            <?= e($p['checksum']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= getPreservationStatusBadge($p['dataset_status']); ?>
                                    </td>
                                    <td>
                                        <?= date('M d, Y H:i', strtotime($p['performed_at'])); ?>
                                    </td>
                                    <td>
                                        <?= e($p['librarian_first_name'] . ' ' . $p['librarian_last_name']); ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="verify.php?dataset_id=<?= (int)$p['dataset_id']; ?>" class="btn-action btn-action-success" title="Re-verify SHA-256 Checksum and file size">
                                            🔍 Re-verify
                                        </a>
                                        <a href="view.php?id=<?= (int)$p['preservation_id']; ?>" class="btn-action" title="View Preservation Record">
                                            👁️ Record
                                        </a>
                                        <a href="history.php?dataset_id=<?= (int)$p['dataset_id']; ?>" class="btn-action" title="View Preservation History">
                                            📜 History
                                        </a>
                                        <?php if ($p['dataset_status'] === 'preserved'): ?>
                                            <a href="archive.php?dataset_id=<?= (int)$p['dataset_id']; ?>" class="btn-action btn-action-warning" title="Move to Long-Term Archive" onclick="return confirm('Confirm: Archive this preserved dataset? (Physical file remains safe in controlled storage)');">
                                                📦 Archive
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section 2: Datasets Awaiting Preservation -->
        <div class="section-card">
            <div class="section-header-bar">
                <h2 class="section-title">
                    ⏳ Datasets Awaiting Preservation (<?= count($awaitingList); ?>)
                </h2>
            </div>

            <?php if (empty($awaitingList)): ?>
                <div class="empty-placeholder">
                    No datasets currently require preservation.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Dataset</th>
                                <th>Project</th>
                                <th>Depositor</th>
                                <th>Active Version</th>
                                <th>File Name & Size</th>
                                <th>Format</th>
                                <th>Recorded SHA-256</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($awaitingList as $aw): ?>
                                <tr>
                                    <td>
                                        <a href="../datasets/view.php?id=<?= (int)$aw['dataset_id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                            <?= e($aw['dataset_title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="project-code-badge"><?= e($aw['project_code']); ?></span>
                                    </td>
                                    <td>
                                        <?= e($aw['owner_first_name'] . ' ' . $aw['owner_last_name']); ?>
                                    </td>
                                    <td>
                                        <strong>v<?= (int)$aw['current_version']; ?></strong>
                                    </td>
                                    <td>
                                        <div><?= e($aw['file_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= formatFileSize((int)$aw['file_size']); ?></div>
                                    </td>
                                    <td>
                                        <?= e($aw['mime_type']); ?>
                                    </td>
                                    <td>
                                        <div class="checksum-cell" title="<?= e($aw['checksum']); ?>">
                                            <?= e($aw['checksum']); ?>
                                        </div>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="create.php?dataset_id=<?= (int)$aw['dataset_id']; ?>" class="btn-action btn-action-primary">
                                            🛡️ Preserve Dataset &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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
