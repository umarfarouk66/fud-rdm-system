<?php
/**
 * Data Management Plans (DMP) Directory
 * RDM Information System - Step 6
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dmp_helpers.php';

// Require authentication
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Flash messages
$successMsg = $_SESSION['dmp_success'] ?? null;
$errorMsg   = $_SESSION['dmp_error'] ?? null;
unset($_SESSION['dmp_success'], $_SESSION['dmp_error']);

// Query DMPs based on user authorization
$dmps = [];
try {
    if ($systemRole === 'admin' || $systemRole === 'supervisor' || $systemRole === 'librarian') {
        // Administrative / Supervisory view
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                p.project_code,
                p.title AS project_title,
                p.owner_id AS project_owner_id,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name,
                pm.role AS user_member_role
            FROM data_management_plans d
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON p.owner_id = u.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            ORDER BY d.updated_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $dmps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Researcher view (only projects where user is owner or member)
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                p.project_code,
                p.title AS project_title,
                p.owner_id AS project_owner_id,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name,
                pm.role AS user_member_role
            FROM data_management_plans d
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON p.owner_id = u.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            WHERE p.owner_id = :user_id OR pm.user_id = :user_id
            GROUP BY d.id
            ORDER BY d.updated_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $dmps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("DMP Index Query Error: " . $e->getMessage());
    $errorMsg = "Unable to retrieve Data Management Plans from database.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Management Plans (DMP) — FUD RDM System</title>
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
        .dmp-table-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .dmp-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .dmp-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .dmp-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .dmp-table tr:last-child td {
            border-bottom: none;
        }
        .dmp-table tr:hover td {
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
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
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

        <!-- Notifications -->
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
                <h1>Data Management Plans (DMPs)</h1>
                <p>Author, review and govern institutional research data management plans</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    ➕ Create New DMP
                </a>
            </div>
        </div>

        <?php if (empty($dmps)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">📝</div>
                <h3>No Data Management Plans Found</h3>
                <p>
                    Create a DMP for your research project to define how research data will be collected, stored, protected, shared, preserved and disposed of.
                </p>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                    ➕ Create DMP
                </a>
            </div>
        <?php else: ?>
            <!-- DMP Table -->
            <div class="dmp-table-container">
                <table class="dmp-table">
                    <thead>
                        <tr>
                            <th>DMP ID</th>
                            <th>Project Code</th>
                            <th>Research Project Title</th>
                            <th>Lead Researcher</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dmps as $dmp): 
                            $isOwner = ((int)$dmp['project_owner_id'] === $userId);
                            $memberRole = $isOwner ? 'owner' : ($dmp['user_member_role'] ?? null);
                            $canEdit = canEditDmp($memberRole, $systemRole, $dmp['status']);
                            $canSubmit = canSubmitDmp($memberRole, $systemRole, $dmp['status']);
                        ?>
                            <tr>
                                <td>
                                    <strong>#<?= (int)$dmp['id']; ?></strong>
                                </td>
                                <td>
                                    <span class="project-code-badge"><?= e($dmp['project_code']); ?></span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= (int)$dmp['id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                        <?= e($dmp['project_title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?= e($dmp['owner_first_name'] . ' ' . $dmp['owner_last_name']); ?>
                                    <?php if ($isOwner): ?>
                                        <span style="font-size: 0.7rem; background: #e0e7ff; color: #3730a3; padding: 2px 5px; border-radius: 3px; font-weight: 600;">You</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= getDmpStatusBadge($dmp['status']); ?>
                                </td>
                                <td>
                                    <?= date('M d, Y H:i', strtotime($dmp['updated_at'])); ?>
                                </td>
                                <td class="actions-cell">
                                    <a href="view.php?id=<?= (int)$dmp['id']; ?>" class="btn-action" title="View DMP Details">
                                        👁️ View
                                    </a>

                                    <?php if ($canEdit): ?>
                                        <a href="edit.php?id=<?= (int)$dmp['id']; ?>" class="btn-action" title="Edit DMP">
                                            ✏️ Edit
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canSubmit): ?>
                                        <form action="submit.php" method="POST" style="display: inline;" onsubmit="return confirm('Submit this Data Management Plan for supervisor and steward review?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="dmp_id" value="<?= (int)$dmp['id']; ?>">
                                            <button type="submit" class="btn-action btn-action-success" title="Submit for Review">
                                                🚀 Submit
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
