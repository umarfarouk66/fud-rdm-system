<?php
/**
 * Datasets Repository Directory
 * RDM Information System - Step 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Flash notifications
$successMsg = $_SESSION['dataset_success'] ?? null;
$errorMsg   = $_SESSION['dataset_error'] ?? null;
unset($_SESSION['dataset_success'], $_SESSION['dataset_error']);

// Query accessible datasets
$datasets = [];
try {
    if ($systemRole === 'admin' || $systemRole === 'supervisor' || $systemRole === 'librarian') {
        // Administrative / Supervisory view: see all datasets
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                p.project_code,
                p.title AS project_title,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name,
                pm.role AS user_project_role
            FROM datasets d
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON d.owner_id = u.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $datasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Researcher view: datasets they own, project members, or public datasets
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                p.project_code,
                p.title AS project_title,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name,
                pm.role AS user_project_role
            FROM datasets d
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON d.owner_id = u.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            WHERE d.owner_id = :user_id 
               OR pm.user_id = :user_id
               OR d.access_level = 'public'
            GROUP BY d.id
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $datasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Datasets Index Query Error: " . $e->getMessage());
    $errorMsg = "Unable to retrieve datasets from database.";
}

// Access badge styling helper
function getAccessLevelBadge(string $level): string {
    switch (strtolower(trim($level))) {
        case 'public':
            return '<span class="status-badge status-public" style="background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;">Public</span>';
        case 'restricted':
            return '<span class="status-badge status-restricted" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">Restricted</span>';
        case 'private':
        default:
            return '<span class="status-badge status-private" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;">Private</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datasets Repository — FUD RDM System</title>
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
        .datasets-table-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .datasets-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .datasets-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .datasets-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .datasets-table tr:last-child td {
            border-bottom: none;
        }
        .datasets-table tr:hover td {
            background-color: #f8fafc;
        }
        .project-code-badge {
            font-family: monospace;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.25rem 0.55rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
        }
        .version-tag {
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: 700;
            background: #eff6ff;
            color: #1e40af;
            padding: 0.2rem 0.45rem;
            border-radius: 4px;
        }
        .actions-cell {
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
        .btn-action-danger {
            color: #dc2626;
            background-color: #fef2f2;
            border-color: #fecaca;
        }
        .btn-action-danger:hover {
            background-color: #dc2626;
            color: #ffffff;
        }
        .empty-state {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: var(--radius-lg);
            padding: 3.5rem 1.5rem;
            text-align: center;
            max-width: 600px;
            margin: 2rem auto;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .empty-state h3 {
            font-size: 1.25rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            line-height: 1.6;
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
                <h1>Research Datasets Repository</h1>
                <p>Deposit, version, download and govern institutional research datasets</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    ➕ Deposit New Dataset
                </a>
            </div>
        </div>

        <?php if (empty($datasets)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">📊</div>
                <h3>No Datasets Deposited Yet</h3>
                <p>
                    No datasets have been deposited yet. Create a dataset and securely deposit your research data.
                </p>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                    ➕ Create Dataset
                </a>
            </div>
        <?php else: ?>
            <!-- Datasets Table -->
            <div class="datasets-table-container">
                <table class="datasets-table">
                    <thead>
                        <tr>
                            <th>Dataset Title</th>
                            <th>Project</th>
                            <th>Type</th>
                            <th>Access</th>
                            <th>Version</th>
                            <th>Size</th>
                            <th>Downloads</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($datasets as $ds): 
                            $ownerId = (int)$ds['owner_id'];
                            $projectRole = $ds['user_project_role'] ?? null;
                            $canEdit = canEditDataset($projectRole, $systemRole, $ownerId, $userId);
                            $canDownload = canDownloadDataset($projectRole, $systemRole, $ds['access_level'], $ownerId, $userId);
                            $canDelete = canDeleteDataset($projectRole, $systemRole, $ownerId, $userId);
                        ?>
                            <tr>
                                <td>
                                    <a href="view.php?id=<?= (int)$ds['id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                        <?= e($ds['title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="project-code-badge"><?= e($ds['project_code']); ?></span>
                                </td>
                                <td><?= e($ds['dataset_type'] ?: 'Data File'); ?></td>
                                <td><?= getAccessLevelBadge($ds['access_level']); ?></td>
                                <td><span class="version-tag">v<?= (int)$ds['current_version']; ?></span></td>
                                <td><?= formatFileSize((int)$ds['total_size']); ?></td>
                                <td><?= (int)$ds['download_count']; ?></td>
                                <td><?= date('M d, Y', strtotime($ds['created_at'])); ?></td>
                                <td class="actions-cell">
                                    <a href="view.php?id=<?= (int)$ds['id']; ?>" class="btn-action" title="View Dataset Details">
                                        👁️ View
                                    </a>

                                    <?php if ($canDownload): ?>
                                        <a href="download.php?id=<?= (int)$ds['id']; ?>" class="btn-action btn-action-success" title="Download Dataset">
                                            ⬇️ Download
                                        </a>
                                    <?php endif; ?>

                                    <a href="versions.php?id=<?= (int)$ds['id']; ?>" class="btn-action" title="Version History">
                                        📑 Versions
                                    </a>

                                    <?php if ($canEdit): ?>
                                        <a href="edit.php?id=<?= (int)$ds['id']; ?>" class="btn-action" title="Edit Metadata">
                                            ✏️ Edit
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete): ?>
                                        <form action="delete.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this dataset and all associated file versions?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="dataset_id" value="<?= (int)$ds['id']; ?>">
                                            <button type="submit" class="btn-action btn-action-danger" title="Delete Dataset">
                                                🗑️
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
