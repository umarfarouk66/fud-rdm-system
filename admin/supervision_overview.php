<?php
/**
 * Admin University-wide Supervision Overview & Progress Center
 * FUD RDM System - Phase 6: Advanced Supervision, Milestones & Progress Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';

// Enforce admin role (super_admin automatically allowed read-only)
requireRole('admin');

$user       = currentUser();
$userId     = (int)$user['id'];
$isSuper    = isSuperAdmin();

// Calculate university-wide supervision statistics
$stats = [
    'total_students'      => 0,
    'assigned_students'   => 0,
    'unassigned_students' => 0,
    'total_supervisors'   => 0,
    'active_projects'     => 0,
    'pending_reviews'     => 0,
    'open_corrections'    => 0,
    'overdue_milestones'  => 0,
    'completed_projects'  => 0
];

// 1. Student stats
$stCountStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN ss.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    LEFT JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
    WHERE r.name = 'researcher'
");
$stCounts = $stCountStmt->fetch(PDO::FETCH_ASSOC);
$stats['total_students']      = (int)($stCounts['total'] ?? 0);
$stats['assigned_students']   = (int)($stCounts['assigned'] ?? 0);
$stats['unassigned_students'] = max(0, $stats['total_students'] - $stats['assigned_students']);

// 2. Supervisor count
$supCountStmt = $pdo->query("
    SELECT COUNT(*) FROM users u 
    INNER JOIN roles r ON u.role_id = r.id 
    WHERE r.name = 'supervisor' AND u.status = 'active'
");
$stats['total_supervisors'] = (int)$supCountStmt->fetchColumn();

// 3. Project stats
$projStatsStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status NOT IN ('archived', 'completed', 'final_approved') THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN status IN ('completed', 'final_approved') THEN 1 ELSE 0 END) AS completed_cnt
    FROM research_projects
");
$pStats = $projStatsStmt->fetch(PDO::FETCH_ASSOC);
$stats['active_projects']    = (int)($pStats['active_cnt'] ?? 0);
$stats['completed_projects'] = (int)($pStats['completed_cnt'] ?? 0);

// 4. Pending reviews
$revCountStmt = $pdo->query("
    SELECT COUNT(*) FROM project_submissions 
    WHERE status IN ('submitted', 'under_review')
");
$stats['pending_reviews'] = (int)$revCountStmt->fetchColumn();

// 5. Open corrections
$corrCountStmt = $pdo->query("
    SELECT COUNT(*) FROM project_corrections 
    WHERE status IN ('open', 'addressed')
");
$stats['open_corrections'] = (int)$corrCountStmt->fetchColumn();

// 6. Overdue milestones
$overdueCountStmt = $pdo->query("
    SELECT COUNT(*) FROM project_milestones 
    WHERE status != 'completed' AND due_date IS NOT NULL AND due_date < NOW()
");
$stats['overdue_milestones'] = (int)$overdueCountStmt->fetchColumn();

// Filter parameters
$search    = trim($_GET['search'] ?? '');
$deptFilter = trim($_GET['department'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// Fetch student projects list for monitoring
$query = "
    SELECT 
        u.id AS student_id, u.first_name, u.last_name, u.email, u.matric_number, u.department, u.faculty, u.academic_level,
        p.name AS programme_name,
        ss.supervisor_id,
        sup.first_name AS sup_first_name, sup.last_name AS sup_last_name,
        rp.id AS project_id, rp.project_code, rp.title AS project_title, rp.status AS project_status, rp.updated_at AS last_activity
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id AND r.name = 'researcher'
    LEFT JOIN programmes p ON u.programme_id = p.id
    LEFT JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
    LEFT JOIN users sup ON ss.supervisor_id = sup.id
    LEFT JOIN research_projects rp ON u.id = rp.owner_id AND rp.status != 'archived'
    WHERE 1=1
";

$params = [];

if ($search !== '') {
    $query .= " AND (u.first_name LIKE :s OR u.last_name LIKE :s OR u.matric_number LIKE :s OR rp.title LIKE :s OR rp.project_code LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($deptFilter !== '') {
    $query .= " AND u.department = :dept";
    $params[':dept'] = $deptFilter;
}
if ($statusFilter !== '') {
    if ($statusFilter === 'unassigned') {
        $query .= " AND ss.id IS NULL";
    } elseif ($statusFilter === 'assigned') {
        $query .= " AND ss.id IS NOT NULL";
    } else {
        $query .= " AND rp.status = :pstatus";
        $params[':pstatus'] = $statusFilter;
    }
}

$query .= " ORDER BY u.last_name ASC, u.first_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$studentRoster = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct departments for filter dropdown
$deptStmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departmentsList = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervision Overview & Progress — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            border-radius: var(--radius-sm);
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-num {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1.1;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table th, .data-table td {
            padding: 0.75rem 0.85rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .progress-mini {
            background: #e2e8f0;
            border-radius: 9999px;
            height: 8px;
            overflow: hidden;
            width: 100px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 0.35rem;
        }
        .progress-mini-fill {
            height: 100%;
            background: #10b981;
        }
        .badge-read-only {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
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
                <div class="dash-brand-subtitle">Federal University Dutse</div>
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
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: 'Institutional Admin'); ?></div>
                </div>
                <?php if ($isSuper): ?>
                    <span class="role-badge role-badge-super-admin" style="background: #7c3aed; color: #fff;">Super Admin (Read-Only)</span>
                <?php else: ?>
                    <span class="role-badge role-badge-admin">Admin</span>
                <?php endif; ?>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
            <?php if ($isSuper): ?>
                <span class="badge-read-only">👁️ Read-Only Mode Active</span>
            <?php endif; ?>
        </div>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                📊 University-Wide Supervision & Progress Overview
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Monitor student research project progression, supervisor allocations, milestones, pending reviews, and overdue items across faculties.
            </p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div>
                    <div class="stat-num"><?= $stats['total_students']; ?></div>
                    <div class="stat-label">Total Students (<?= $stats['unassigned_students']; ?> Unassigned)</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">👨‍🏫</div>
                <div>
                    <div class="stat-num"><?= $stats['total_supervisors']; ?></div>
                    <div class="stat-label">Active Supervisors</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📂</div>
                <div>
                    <div class="stat-num"><?= $stats['active_projects']; ?></div>
                    <div class="stat-label">Active Projects</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div>
                    <div class="stat-num" style="color: #d97706;"><?= $stats['pending_reviews']; ?></div>
                    <div class="stat-label">Submissions Pending Review</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🛠</div>
                <div>
                    <div class="stat-num" style="color: #dc2626;"><?= $stats['open_corrections']; ?></div>
                    <div class="stat-label">Open Correction Items</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div>
                    <div class="stat-num" style="color: #b91c1c;"><?= $stats['overdue_milestones']; ?></div>
                    <div class="stat-label">Overdue Milestones</div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-card">
            <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 220px;">
                    <label class="form-label" style="font-size: 0.8rem;">Search Student / Title / Code</label>
                    <input type="text" name="search" class="form-control" placeholder="Search name, matric or project..." value="<?= e($search); ?>">
                </div>

                <div style="min-width: 180px;">
                    <label class="form-label" style="font-size: 0.8rem;">Department Filter</label>
                    <select name="department" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentsList as $d): ?>
                            <option value="<?= e($d); ?>" <?= $deptFilter === $d ? 'selected' : ''; ?>><?= e($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="min-width: 180px;">
                    <label class="form-label" style="font-size: 0.8rem;">Supervision Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : ''; ?>>Assigned Only</option>
                        <option value="unassigned" <?= $statusFilter === 'unassigned' ? 'selected' : ''; ?>>Unassigned Only</option>
                        <option value="topic_submitted" <?= $statusFilter === 'topic_submitted' ? 'selected' : ''; ?>>Topic Submitted</option>
                        <option value="proposal_submitted" <?= $statusFilter === 'proposal_submitted' ? 'selected' : ''; ?>>Proposal Submitted</option>
                        <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 700;">Filter</button>
                    <a href="supervision_overview.php" class="btn-logout" style="padding: 0.55rem 1rem; border: 1px solid var(--border-color); background: #fff; color: var(--text-main); text-decoration: none;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Roster & Progress Table -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                👨‍🎓 Student Research Roster & Stage Progress (<?= count($studentRoster); ?>)
            </h3>

            <?php if (empty($studentRoster)): ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🔍</div>
                    <p style="font-size: 0.95rem;">No student records found matching the specified filters.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Researcher</th>
                                <th>Matric Number</th>
                                <th>Department</th>
                                <th>Assigned Supervisor</th>
                                <th>Research Project</th>
                                <th>Current Stage & Progress</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentRoster as $row): 
                                $projId = (int)($row['project_id'] ?? 0);
                                $progressPct = 0;
                                $stageName = 'No Project Registered';
                                if ($projId > 0) {
                                    $pData = getProjectSupervisionData($pdo, $projId);
                                    $progressPct = (int)$pData['progress_percentage'];
                                    $stageName = $pData['current_stage_label'];
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-color);">
                                            <?= e($row['first_name'] . ' ' . $row['last_name']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($row['email']); ?></div>
                                    </td>
                                    <td style="font-family: monospace; font-weight: 700; font-size: 0.85rem;">
                                        <?= e($row['matric_number'] ?: 'N/A'); ?>
                                    </td>
                                    <td style="font-size: 0.85rem;">
                                        <?= e($row['department'] ?: 'Unassigned'); ?>
                                    </td>
                                    <td>
                                        <?php if ($row['supervisor_id']): ?>
                                            <span style="font-weight: 600; color: #065f46; background: #ecfdf5; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                Prof./Dr. <?= e($row['sup_first_name'] . ' ' . $row['sup_last_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-weight: 700; font-size: 0.75rem; background: #fee2e2; padding: 0.15rem 0.45rem; border-radius: 4px;">
                                                Unassigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($projId > 0): ?>
                                            <a href="../projects/view.php?id=<?= $projId; ?>" style="font-weight: 700; color: var(--primary-color); text-decoration: none;">
                                                <?= e($row['project_title']); ?>
                                            </a>
                                            <div style="font-family: monospace; font-size: 0.75rem; color: var(--accent-color);"><?= e($row['project_code']); ?></div>
                                        <?php else: ?>
                                            <span style="font-style: italic; color: var(--text-muted); font-size: 0.85rem;">Not Registered</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($projId > 0): ?>
                                            <div>
                                                <div class="progress-mini">
                                                    <div class="progress-mini-fill" style="width: <?= $progressPct; ?>%;"></div>
                                                </div>
                                                <strong style="font-size: 0.8rem; color: #059669;"><?= $progressPct; ?>%</strong>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                                📍 <?= e($stageName); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($projId > 0): ?>
                                            <a href="../projects/view.php?id=<?= $projId; ?>" class="btn-sm" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                View Project &rarr;
                                            </a>
                                        <?php else: ?>
                                            <a href="supervisors.php" class="btn-sm" style="background: #e0e7ff; color: #3730a3; text-decoration: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                Assign Supervisor
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
