<?php
/**
 * User Directory & Administration
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

$feedbackMessage = $_SESSION['admin_success'] ?? '';
$errorMessage    = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Handle Quick Status Toggle POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission();
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (invalid CSRF token).';
    } else {
        $action       = $_POST['action'] ?? '';
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($action === 'toggle_status' && $targetUserId > 0) {
            // Fetch target user current info
            $tStmt = $pdo->prepare("SELECT id, first_name, last_name, email, role_id, status FROM users WHERE id = ?");
            $tStmt->execute([$targetUserId]);
            $target = $tStmt->fetch(PDO::FETCH_ASSOC);

            if ($target) {
                $newStatus = ($target['status'] === 'active') ? 'inactive' : 'active';

                // Check last-admin and self-protection
                $check = validateAdminModification($pdo, $targetUserId, $adminId, (int)$target['role_id'], $newStatus);
                if (!$check['allowed']) {
                    $errorMessage = $check['error'];
                } else {
                    $upStmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $upStmt->execute([$newStatus, $targetUserId]);

                    // Audit action
                    $auditAction = ($newStatus === 'active') ? 'user_activated' : 'user_deactivated';
                    log_audit(
                        $pdo,
                        $auditAction,
                        'users',
                        $targetUserId,
                        "Administrator '{$adminUser['name']}' (#{$adminId}) changed user '{$target['first_name']} {$target['last_name']}' (#{$targetUserId}) status to '{$newStatus}'"
                    );

                    // Notify affected user
                    createNotification(
                        $pdo,
                        $targetUserId,
                        'system_alert',
                        'Account Status Updated',
                        "Your account status has been set to '" . ucfirst($newStatus) . "' by the system administrator.",
                        'user',
                        $targetUserId
                    );

                    $feedbackMessage = "Account status for '{$target['first_name']} {$target['last_name']}' updated to " . ucfirst($newStatus) . ".";
                }
            } else {
                $errorMessage = 'The target user was not found.';
            }
        }
    }
}

// Search and Filter Parameters
$search = trim($_GET['q'] ?? '');
$roleFilter = strtolower(trim($_GET['role'] ?? ''));
$statusFilter = strtolower(trim($_GET['status'] ?? ''));

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(50, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// Build Prepared Query
$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q OR u.institution LIKE :q OR u.faculty LIKE :q OR u.department LIKE :q OR u.matric_number LIKE :q OR sp.staff_id LIKE :q)";
    $params[':q'] = "%{$search}%";
}

if (in_array($roleFilter, ['researcher', 'supervisor', 'librarian', 'admin', 'super_admin'], true)) {
    $where[] = "r.name = :role";
    $params[':role'] = $roleFilter;
}

if (in_array($statusFilter, ['active', 'inactive', 'suspended'], true)) {
    $where[] = "u.status = :status";
    $params[':status'] = $statusFilter;
}

$whereClause = implode(" AND ", $where);

// Count Total Matching Users
$countStmt = $pdo->prepare("
    SELECT COUNT(u.id)
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
    WHERE {$whereClause}
");
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $limit));

// Fetch Paginated User Records
$userStmt = $pdo->prepare("
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.institution,
        u.faculty,
        u.department,
        u.matric_number,
        u.status,
        u.created_at,
        u.last_login_at,
        r.name AS role_name,
        sp.staff_id,
        sp.max_capacity
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
    WHERE {$whereClause}
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$userStmt->execute($params);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

// Notification count for navbar
$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — FUD RDM System</title>
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
            min-width: 160px;
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
            font-size: 0.9rem;
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
            font-size: 0.9rem;
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
            vertical-align: middle;
        }
        .data-table tr:hover {
            background: #f8fafc;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            display: inline-block;
        }
        .status-active    { background: #d1fae5; color: #065f46; }
        .status-inactive  { background: #f1f5f9; color: #475569; }
        .status-suspended { background: #fee2e2; color: #991b1b; }
        
        .btn-table {
            padding: 0.35rem 0.65rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-table:hover {
            background: #f1f5f9;
            color: var(--primary-color);
        }
        .btn-table-primary {
            background: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-table-primary:hover {
            background: #1e1b4b;
            color: #ffffff;
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

        <!-- Navigation Breadcrumbs -->
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
            <div style="font-size: 0.9rem; color: var(--text-muted);">
                Showing <strong><?= count($users); ?></strong> of <strong><?= $totalUsers; ?></strong> users
            </div>
        </div>

        <!-- Feedback / Alerts -->
        <?php if (!empty($feedbackMessage)): ?>
            <div class="dash-alert dash-alert-success" style="margin-bottom: 1.5rem;">
                <span><?= e($feedbackMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem;">
                <span><?= e($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                👥 User Management Directory
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Manage registered institutional users, update access roles and govern account activation states.
            </p>
        </div>

        <!-- Filter & Search Form -->
        <form method="GET" action="users.php" class="filter-panel">
            <div class="filter-group" style="flex: 2; min-width: 220px;">
                <label for="q">Search Users</label>
                <input type="text" id="q" name="q" value="<?= e($search); ?>" placeholder="Search by name, email, department...">
            </div>

            <div class="filter-group">
                <label for="role">Role Filter</label>
                <select id="role" name="role">
                    <option value="">All Roles</option>
                    <option value="researcher" <?= ($roleFilter === 'researcher') ? 'selected' : ''; ?>>Researcher</option>
                    <option value="supervisor" <?= ($roleFilter === 'supervisor') ? 'selected' : ''; ?>>Supervisor</option>
                    <option value="librarian" <?= ($roleFilter === 'librarian') ? 'selected' : ''; ?>>Librarian / Steward</option>
                    <option value="admin" <?= ($roleFilter === 'admin') ? 'selected' : ''; ?>>Administrator</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="status">Status Filter</label>
                <select id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?= ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?= ($statusFilter === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.55rem 1.25rem; font-weight: 600; border-radius: var(--radius-sm); border: none; cursor: pointer;">
                    Filter Users
                </button>
                <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                    <a href="users.php" style="margin-left: 0.5rem; font-size: 0.85rem; color: var(--text-muted); text-decoration: underline;">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- User Table -->
        <div class="data-table-card">
            <?php if (empty($users)): ?>
                <div style="padding: 3rem 1.5rem; text-align: center; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🔍</div>
                    <h3 style="font-size: 1.15rem; color: var(--primary-color); margin-bottom: 0.25rem;">No Users Found</h3>
                    <p style="font-size: 0.9rem;">No institutional user accounts matched your search criteria.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User Name</th>
                            <th>Email</th>
                            <th>System Role</th>
                            <th>Affiliation</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Last Login</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): 
                            $fullName = trim($u['first_name'] . ' ' . $u['last_name']);
                            $roleName = strtolower(trim($u['role_name']));
                            $status   = strtolower(trim($u['status']));
                        ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary-color);"><?= e($fullName); ?></strong>
                                    <?php if ((int)$u['id'] === $adminId): ?>
                                        <span style="font-size: 0.7rem; background: #e0e7ff; color: #3730a3; padding: 0.1rem 0.35rem; border-radius: 4px; margin-left: 0.3rem;">You</span>
                                    <?php endif; ?>
                                    <?php if (!empty($u['matric_number'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--accent-color); font-family: monospace;">Matric: <?= e($u['matric_number']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($u['staff_id'])): ?>
                                        <div style="font-size: 0.75rem; color: #059669; font-family: monospace;">Staff ID: <?= e($u['staff_id']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="mailto:<?= e($u['email']); ?>" style="color: var(--accent-color); text-decoration: none;">
                                        <?= e($u['email']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="role-badge role-badge-<?= e($roleName); ?>">
                                        <?= e(ucfirst($roleName)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?= e($u['institution'] ?: 'Institutional User'); ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($u['department'] ?: ($u['faculty'] ?: '—')); ?></div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= e($status); ?>">
                                        <?= e(ucfirst($status)); ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                    <?= date('M d, Y', strtotime($u['created_at'])); ?>
                                </td>
                                <td style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                    <?= !empty($u['last_login_at']) ? date('M d, Y H:i', strtotime($u['last_login_at'])) : 'Never'; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 0.35rem; align-items: center;">
                                        <a href="user_view.php?id=<?= (int)$u['id']; ?>" class="btn-table" title="View profile and activity">
                                            👁️ View Details
                                        </a>
                                        <?php if (!isSuperAdmin()): ?>
                                            <a href="user_edit.php?id=<?= (int)$u['id']; ?>" class="btn-table btn-table-primary" title="Edit user details and role">
                                                ✏️ Edit
                                            </a>
                                            <?php if ((int)$u['id'] !== $adminId): ?>
                                                <form method="POST" action="users.php?<?= http_build_query($_GET); ?>" style="display: inline; margin: 0;">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                                                    <button type="submit" class="btn-table" onclick="return confirm('Are you sure you want to <?= ($status === 'active') ? 'deactivate' : 'activate'; ?> this user account?');" style="<?= ($status === 'active') ? 'color: #991b1b;' : 'color: #065f46;'; ?>">
                                                        <?= ($status === 'active') ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: #6366f1; background: #e0e7ff; padding: 0.2rem 0.5rem; border-radius: 4px; font-weight: 700;">👁️ Read-Only</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Pagination Links -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem;">
                <?php for ($p = 1; $p <= $totalPages; $p++): 
                    $pageParams = array_merge($_GET, ['page' => $p]);
                ?>
                    <a href="users.php?<?= http_build_query($pageParams); ?>" style="padding: 0.4rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 600; text-decoration: none; <?= ($p === $page) ? 'background: var(--primary-color); color: #ffffff;' : 'background: #ffffff; color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
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
