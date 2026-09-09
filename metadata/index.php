<?php
/**
 * Dataset Metadata Directory
 * RDM Information System - Step 8
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Flash notifications
$successMsg = $_SESSION['metadata_success'] ?? null;
$errorMsg   = $_SESSION['metadata_error'] ?? null;
unset($_SESSION['metadata_success'], $_SESSION['metadata_error']);

// Query metadata records for accessible datasets
$metadataList = [];
try {
    if ($systemRole === 'admin' || $systemRole === 'supervisor' || $systemRole === 'librarian') {
        // Administrative / Supervisory view
        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                d.owner_id AS dataset_owner_id,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                pm.role AS user_project_role
            FROM dataset_metadata m
            INNER JOIN datasets d ON m.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            ORDER BY m.updated_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $metadataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Researcher view
        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                d.owner_id AS dataset_owner_id,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                pm.role AS user_project_role
            FROM dataset_metadata m
            INNER JOIN datasets d ON m.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            WHERE d.owner_id = :user_id 
               OR pm.user_id = :user_id 
               OR d.access_level = 'public'
            GROUP BY m.id
            ORDER BY m.updated_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $metadataList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Metadata Index Query Error: " . $e->getMessage());
    $errorMsg = "Unable to retrieve metadata records from database.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dataset Metadata Directory — FUD RDM System</title>
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
        .metadata-table-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .metadata-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .metadata-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .metadata-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .metadata-table tr:last-child td {
            border-bottom: none;
        }
        .metadata-table tr:hover td {
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
        .badge-complete {
            background: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-mostly-complete {
            background: #fef3c7;
            color: #92400e;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-incomplete {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
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
                <h1>Dataset Descriptive Metadata</h1>
                <p>Manage structured metadata, methodological provenance and licensing for deposited research data</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    ➕ Create Metadata
                </a>
            </div>
        </div>

        <?php if (empty($metadataList)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">🏷️</div>
                <h3>No Metadata Records Found</h3>
                <p>
                    No metadata has been added for this dataset yet. Adding structured metadata makes research data discoverable, citable and compliant with FAIR data principles.
                </p>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                    ➕ Create Metadata
                </a>
            </div>
        <?php else: ?>
            <!-- Metadata Table -->
            <div class="metadata-table-container">
                <table class="metadata-table">
                    <thead>
                        <tr>
                            <th>Dataset Title</th>
                            <th>Project</th>
                            <th>Creator</th>
                            <th>Subject Area</th>
                            <th>Data Type</th>
                            <th>Format</th>
                            <th>Creation Date</th>
                            <th>Completeness</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($metadataList as $m): 
                            $canEdit = canManageDatasetMetadata($m['user_project_role'] ?? null, $systemRole, (int)$m['dataset_owner_id'], $userId);
                            $completeness = calculateMetadataCompleteness($m);
                        ?>
                            <tr>
                                <td>
                                    <a href="view.php?id=<?= (int)$m['id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                        <?= e($m['dataset_title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="project-code-badge"><?= e($m['project_code']); ?></span>
                                </td>
                                <td><?= e($m['creator'] ?: 'Not Specified'); ?></td>
                                <td><?= e($m['subject_area'] ?: 'General'); ?></td>
                                <td><?= e($m['data_type'] ?: 'Data'); ?></td>
                                <td><?= e($m['file_format'] ?: 'Unknown'); ?></td>
                                <td><?= !empty($m['creation_date']) ? date('M d, Y', strtotime($m['creation_date'])) : '—'; ?></td>
                                <td>
                                    <span class="<?= $completeness['badge_class']; ?>">
                                        <?= $completeness['percentage']; ?>% (<?= $completeness['status']; ?>)
                                    </span>
                                </td>
                                <td class="actions-cell">
                                    <a href="view.php?id=<?= (int)$m['id']; ?>" class="btn-action" title="View Detailed Metadata">
                                        👁️ View
                                    </a>

                                    <?php if ($canEdit): ?>
                                        <a href="edit.php?id=<?= (int)$m['id']; ?>" class="btn-action" title="Edit Metadata">
                                            ✏️ Edit
                                        </a>
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
