<?php
/**
 * Research Projects Directory & Discovery Portal
 * FUD RDM System - Step 5 Collaboration & Project Governance
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = (int)$user['id'];
$systemRole = $user['role'];

// Flash messages
$successMsg = $_SESSION['project_success'] ?? null;
$errorMsg   = $_SESSION['project_error'] ?? null;
unset($_SESSION['project_success'], $_SESSION['project_error']);

// Search & Filter state
$rawQuery       = trim($_GET['q'] ?? '');
$selectedScope  = strtolower(trim($_GET['scope'] ?? 'all'));
$selectedStatus = strtolower(trim($_GET['status'] ?? 'all'));

// Query projects based on system role, scope and search query
$projects = [];
try {
    $where = [];
    $params = [':current_user_id' => $userId];

    // 1. Role & Scope Visibility Control
    if ($selectedScope === 'my') {
        $where[] = "(p.owner_id = :uid1 OR pm.user_id = :uid2)";
        $params[':uid1'] = $userId;
        $params[':uid2'] = $userId;
    } else {
        $where[] = "1=1";
    }

    // 2. Search Parameter Filtering (Title, Code, Creator, Description, Research Area, Department, Faculty)
    if ($rawQuery !== '') {
        $where[] = "(
            p.title LIKE :q1
            OR p.project_code LIKE :q2
            OR p.description LIKE :q3
            OR p.objectives LIKE :q4
            OR p.research_area LIKE :q5
            OR p.department LIKE :q6
            OR p.faculty LIKE :q7
            OR u.first_name LIKE :q8
            OR u.last_name LIKE :q9
            OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q10
        )";
        $likeVal = '%' . $rawQuery . '%';
        for ($i = 1; $i <= 10; $i++) {
            $params[":q{$i}"] = $likeVal;
        }
    }

    // 3. Status Filter
    if ($selectedStatus !== 'all' && in_array($selectedStatus, ['active', 'completed', 'planning', 'archived', 'suspended', 'draft'], true)) {
        $where[] = "p.status = :status_filter";
        $params[':status_filter'] = $selectedStatus;
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email,
            pm.role AS user_member_role,
            COUNT(d.id) AS dataset_count
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :current_user_id
        LEFT JOIN datasets d ON p.id = d.project_id AND d.status != 'deleted' AND d.access_level IN ('public', 'restricted')
        WHERE {$whereClause}
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Projects Index Query Error: " . $e->getMessage());
    $errorMsg = "Unable to retrieve projects from database.";
}

// Status badge styling helper
function getStatusBadge(string $status): string {
    switch (strtolower(trim($status))) {
        case 'active':
            return '<span class="status-badge status-active">Active</span>';
        case 'completed':
            return '<span class="status-badge status-completed">Completed</span>';
        case 'archived':
            return '<span class="status-badge status-archived">Archived</span>';
        case 'suspended':
            return '<span class="status-badge status-cancelled">Suspended</span>';
        case 'planning':
        case 'draft':
        default:
            return '<span class="status-badge status-planning">Planning</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Projects Directory & Discovery — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
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
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .filter-form {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-input {
            flex: 1;
            min-width: 240px;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
        }
        .filter-select {
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            background: #ffffff;
        }
        .btn-filter {
            background: var(--primary-color);
            color: #ffffff;
            border: none;
            padding: 0.6rem 1.25rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            cursor: pointer;
        }
        .btn-filter-reset {
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            padding: 0.6rem 1rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
        }
        .projects-table-container {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            overflow-x: auto;
        }
        .projects-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .projects-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .projects-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .projects-table tr:last-child td {
            border-bottom: none;
        }
        .projects-table tr:hover td {
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
            padding: 0.25rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-planning { background-color: #e0e7ff; color: #3730a3; }
        .status-active { background-color: #d1fae5; color: #065f46; }
        .status-completed { background-color: #e2e8f0; color: #334155; }
        .status-archived { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #fef3c7; color: #92400e; }
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
        .btn-action-danger {
            color: #dc2626;
            border-color: #fecaca;
            background-color: #fef2f2;
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

    <!-- Main Content -->
    <main class="dash-container">

        <!-- Notification Alerts -->
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
                <h1>Research Projects Directory & Discovery</h1>
                <p>Search, discover and track institutional research data projects across Federal University Dutse</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    ➕ Create Research Project
                </a>
            </div>
        </div>

        <!-- Search & Filter Card -->
        <div class="filter-card">
            <form action="index.php" method="GET" class="filter-form">
                <input type="text" name="q" value="<?= e($rawQuery); ?>" class="filter-input" placeholder="Search by title, project code, creator name, research area, subject, department...">
                
                <select name="scope" class="filter-select" onchange="this.form.submit();">
                    <option value="all" <?= ($selectedScope === 'all') ? 'selected' : ''; ?>>All Institutional Discoverable Projects</option>
                    <option value="my" <?= ($selectedScope === 'my') ? 'selected' : ''; ?>>My Projects &amp; Memberships</option>
                </select>

                <select name="status" class="filter-select" onchange="this.form.submit();">
                    <option value="all" <?= ($selectedStatus === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="active" <?= ($selectedStatus === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="completed" <?= ($selectedStatus === 'completed') ? 'selected' : ''; ?>>Completed</option>
                    <option value="planning" <?= ($selectedStatus === 'planning') ? 'selected' : ''; ?>>Planning</option>
                    <option value="archived" <?= ($selectedStatus === 'archived') ? 'selected' : ''; ?>>Archived</option>
                </select>

                <button type="submit" class="btn-filter">🔍 Search Projects</button>

                <?php if ($rawQuery !== '' || $selectedScope !== 'all' || $selectedStatus !== 'all'): ?>
                    <a href="index.php" class="btn-filter-reset">Reset Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($projects)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">📁</div>
                <h3>No Matching Research Projects Found</h3>
                <p>
                    No projects match your current search or scope criteria. Try broadening your search or resetting filters.
                </p>
                <a href="create.php" class="btn-action btn-action-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
                    ➕ Create New Project
                </a>
            </div>
        <?php else: ?>
            <!-- Projects Table -->
            <div class="projects-table-container">
                <table class="projects-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Project Title</th>
                            <th>Research Area</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Datasets</th>
                            <th>Lead Investigator</th>
                            <th>My Relation</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $proj): 
                            $isOwner = ((int)$proj['owner_id'] === $userId);
                            $memberRole = $isOwner ? 'owner' : ($proj['user_member_role'] ?? null);
                            $canEdit = canEditProject($memberRole, $systemRole);
                            $canDelete = canDeleteProject($memberRole, $systemRole);
                            $canMembers = canManageMembers($memberRole, $systemRole);
                        ?>
                            <tr>
                                <td>
                                    <span class="project-code-badge"><?= e($proj['project_code']); ?></span>
                                </td>
                                <td>
                                    <a href="view.php?id=<?= (int)$proj['id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                        <?= e($proj['title']); ?>
                                    </a>
                                </td>
                                <td><?= e($proj['research_area'] ?: 'General'); ?></td>
                                <td><?= e($proj['department'] ?: $proj['faculty']); ?></td>
                                <td><?= getStatusBadge($proj['status']); ?></td>
                                <td>
                                    <span style="font-weight: 700; color: #1e3a8a; background: #eff6ff; padding: 2px 7px; border-radius: 4px; font-size: 0.8rem;">
                                        📊 <?= (int)$proj['dataset_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e($proj['owner_first_name'] . ' ' . $proj['owner_last_name']); ?>
                                </td>
                                <td>
                                    <?php if ($isOwner): ?>
                                        <span style="font-size: 0.72rem; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 4px; font-weight: 700;">Owner</span>
                                    <?php elseif ($memberRole): ?>
                                        <span style="font-size: 0.72rem; background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 4px; font-weight: 700; text-transform: capitalize;"><?= e($memberRole); ?></span>
                                    <?php else: ?>
                                        <span style="font-size: 0.72rem; background: #f1f5f9; color: #64748b; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Discoverable</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <a href="view.php?id=<?= (int)$proj['id']; ?>" class="btn-action" title="View Project">
                                        👁️ View
                                    </a>

                                    <?php if ($canEdit): ?>
                                        <a href="edit.php?id=<?= (int)$proj['id']; ?>" class="btn-action" title="Edit Project">
                                            ✏️ Edit
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canMembers): ?>
                                        <a href="members.php?id=<?= (int)$proj['id']; ?>" class="btn-action" title="Team Members">
                                            👥 Team
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canDelete): ?>
                                        <form action="delete.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this research project? This action cannot be undone.');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="project_id" value="<?= (int)$proj['id']; ?>">
                                            <button type="submit" class="btn-action btn-action-danger" title="Delete Project">
                                                🗑️ Delete
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
