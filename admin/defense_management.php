<?php
/**
 * Admin University-wide Defense & Viva Management Center
 * FUD RDM System - Phase 8: Final Project Approval + Defense/Viva Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';

// Enforce admin role (super_admin automatically allowed read-only access)
requireRole('admin');

$user       = currentUser();
$userId     = (int)$user['id'];
$isSuper    = isSuperAdmin();

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Filter parameters
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// Calculate Defense Statistics
$stats = [
    'ready_for_defense'   => 0,
    'defense_scheduled'   => 0,
    'defense_completed'   => 0,
    'final_corrections'   => 0,
    'completed_projects' => 0
];

$statStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN status IN ('final_submission_approved', 'full_draft_approved') THEN 1 ELSE 0 END) AS ready_cnt,
        SUM(CASE WHEN status = 'defense_scheduled' THEN 1 ELSE 0 END) AS sched_cnt,
        SUM(CASE WHEN status = 'defense_completed' THEN 1 ELSE 0 END) AS comp_cnt,
        SUM(CASE WHEN status = 'final_corrections' THEN 1 ELSE 0 END) AS corr_cnt,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done_cnt
    FROM research_projects
");
$sRow = $statStmt->fetch(PDO::FETCH_ASSOC);
if ($sRow) {
    $stats['ready_for_defense']   = (int)($sRow['ready_cnt'] ?? 0);
    $stats['defense_scheduled']   = (int)($sRow['sched_cnt'] ?? 0);
    $stats['defense_completed']   = (int)($sRow['comp_cnt'] ?? 0);
    $stats['final_corrections']   = (int)($sRow['corr_cnt'] ?? 0);
    $stats['completed_projects'] = (int)($sRow['done_cnt'] ?? 0);
}

// Build query for projects in defense phase
$query = "
    SELECT 
        rp.id AS project_id, rp.project_code, rp.title AS project_title, rp.status AS project_status, rp.updated_at,
        u.id AS student_id, u.first_name AS st_first, u.last_name AS st_last, u.email AS st_email, u.matric_number, u.department, u.faculty,
        sup.first_name AS sup_first, sup.last_name AS sup_last,
        d.id AS defense_id, d.defense_title, d.defense_date, d.start_time, d.end_time, d.venue, d.room_location, d.status AS defense_status,
        o.id AS outcome_id, o.outcome, o.decision_date
    FROM research_projects rp
    INNER JOIN users u ON rp.owner_id = u.id
    LEFT JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
    LEFT JOIN users sup ON ss.supervisor_id = sup.id
    LEFT JOIN project_defenses d ON rp.id = d.project_id
    LEFT JOIN defense_outcomes o ON d.id = o.defense_id
    WHERE rp.status IN ('full_draft_submitted', 'full_draft_approved', 'final_submission_submitted', 'final_submission_approved', 'defense_scheduled', 'defense_completed', 'final_corrections', 'approved', 'completed')
";

$params = [];

if ($search !== '') {
    $query .= " AND (u.first_name LIKE :s OR u.last_name LIKE :s OR u.matric_number LIKE :s OR rp.title LIKE :s OR rp.project_code LIKE :s)";
    $params[':s'] = "%{$search}%";
}

if ($statusFilter !== '') {
    $query .= " AND rp.status = :pstatus";
    $params[':pstatus'] = $statusFilter;
}

$query .= " ORDER BY CASE rp.status 
    WHEN 'final_submission_approved' THEN 1
    WHEN 'defense_scheduled' THEN 2
    WHEN 'defense_completed' THEN 3
    WHEN 'final_corrections' THEN 4
    WHEN 'completed' THEN 5
    ELSE 6
END, d.defense_date ASC, rp.updated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$defenseProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all available supervisors/lecturers for panel assignment dropdown
$panelUsersStmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.email, u.department, r.name AS role_name
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    WHERE r.name IN ('supervisor', 'admin') AND u.status = 'active'
    ORDER BY u.last_name ASC, u.first_name ASC
");
$availablePanelUsers = $panelUsersStmt->fetchAll(PDO::FETCH_ASSOC);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense & Viva Management — FUD RDM System</title>
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
            padding: 0.85rem 0.95rem;
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
        .badge-status {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-ready { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-scheduled { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-completed { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }
        .badge-corrections { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-approved { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }

        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            z-index: 999;
            align-items: center;
            justify-content: center;
        }
        .modal-card {
            background: #ffffff;
            border-radius: var(--radius-md);
            max-width: 650px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.75rem;
            box-shadow: var(--shadow-lg);
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

        <!-- Alerts -->
        <?php if ($flashSuccess): ?>
            <div style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-weight: 600;">
                ✅ <?= e($flashSuccess); ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-weight: 600;">
                ⚠️ <?= e($flashError); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🎓 University Defense & Viva Examination Management
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Manage final research project defense schedules, assign examination panels, record viva outcomes, and grant final institutional project completions.
            </p>
        </div>

        <!-- Metrics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📜</div>
                <div>
                    <div class="stat-num" style="color: #047857;"><?= $stats['ready_for_defense']; ?></div>
                    <div class="stat-label">Approved & Ready for Defense</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📅</div>
                <div>
                    <div class="stat-num" style="color: #1e40af;"><?= $stats['defense_scheduled']; ?></div>
                    <div class="stat-label">Scheduled Defenses</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div>
                    <div class="stat-num" style="color: #6b21a8;"><?= $stats['defense_completed']; ?></div>
                    <div class="stat-label">Completed Oral Defenses</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div>
                    <div class="stat-num" style="color: #d97706;"><?= $stats['final_corrections']; ?></div>
                    <div class="stat-label">Post-Defense Corrections</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🎉</div>
                <div>
                    <div class="stat-num" style="color: #059669;"><?= $stats['completed_projects']; ?></div>
                    <div class="stat-label">Completed & Institutional Approved</div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-card">
            <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 240px;">
                    <label class="form-label" style="font-size: 0.8rem;">Search Student / Code / Title</label>
                    <input type="text" name="search" class="form-control" placeholder="Search student name, matric, title..." value="<?= e($search); ?>">
                </div>

                <div style="min-width: 200px;">
                    <label class="form-label" style="font-size: 0.8rem;">Defense Stage</label>
                    <select name="status" class="form-control">
                        <option value="">All Defense Stages</option>
                        <option value="final_submission_approved" <?= $statusFilter === 'final_submission_approved' ? 'selected' : ''; ?>>Approved for Defense</option>
                        <option value="defense_scheduled" <?= $statusFilter === 'defense_scheduled' ? 'selected' : ''; ?>>Defense Scheduled</option>
                        <option value="defense_completed" <?= $statusFilter === 'defense_completed' ? 'selected' : ''; ?>>Defense Completed</option>
                        <option value="final_corrections" <?= $statusFilter === 'final_corrections' ? 'selected' : ''; ?>>Post-Defense Corrections</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : ''; ?>>Final Completed</option>
                    </select>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 700;">Filter</button>
                    <a href="defense_management.php" class="btn-logout" style="padding: 0.55rem 1rem; border: 1px solid var(--border-color); background: #fff; color: var(--text-main); text-decoration: none;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Main Projects Table -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                🏛️ Final Projects & Viva Examination Registry (<?= count($defenseProjects); ?>)
            </h3>

            <?php if (empty($defenseProjects)): ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎓</div>
                    <p style="font-size: 0.95rem;">No projects currently in final approval or defense stages.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student & Department</th>
                                <th>Research Project</th>
                                <th>Supervisor</th>
                                <th>Readiness</th>
                                <th>Defense Schedule & Venue</th>
                                <th>Examination Panel</th>
                                <th>Outcome / Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($defenseProjects as $row): 
                                $pId = (int)$row['project_id'];
                                $dId = (int)($row['defense_id'] ?? 0);
                                $pStatus = strtolower($row['project_status']);
                                $readiness = checkFinalProjectReadiness($pdo, $pId);
                                $panelMembers = $dId > 0 ? getDefensePanel($pdo, $dId) : [];
                                $outcomeData = $dId > 0 ? getDefenseOutcome($pdo, $dId) : null;
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-color);">
                                            <?= e($row['st_first'] . ' ' . $row['st_last']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; font-family: monospace; color: var(--text-muted);"><?= e($row['matric_number']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($row['department']); ?></div>
                                    </td>

                                    <td>
                                        <a href="../projects/view.php?id=<?= $pId; ?>" style="font-weight: 700; color: var(--primary-color); text-decoration: none;">
                                            <?= e($row['project_title']); ?>
                                        </a>
                                        <div style="font-size: 0.75rem; font-family: monospace; color: var(--accent-color);"><?= e($row['project_code']); ?></div>
                                    </td>

                                    <td style="font-size: 0.85rem;">
                                        <?php if ($row['sup_first']): ?>
                                            <span style="font-weight: 600; color: #065f46; background: #ecfdf5; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                                Prof./Dr. <?= e($row['sup_first'] . ' ' . $row['sup_last']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #b91c1c; font-size: 0.75rem;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($readiness['is_ready']): ?>
                                            <span style="background: #dcfce7; color: #15803d; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 9999px;">
                                                ✅ <?= $readiness['ready_count']; ?>/<?= $readiness['total_count']; ?> Ready
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #fef3c7; color: #92400e; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 9999px;">
                                                ⏳ <?= $readiness['ready_count']; ?>/<?= $readiness['total_count']; ?> Checklist
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($dId > 0 && !empty($row['defense_date'])): ?>
                                            <div style="font-weight: 700; color: #1e40af; font-size: 0.85rem;">
                                                📅 <?= date('M d, Y', strtotime($row['defense_date'])); ?> @ <?= e($row['start_time']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                📍 <?= e($row['venue']); ?> <?= $row['room_location'] ? '(' . e($row['room_location']) . ')' : ''; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.8rem; font-style: italic;">Not Scheduled</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($panelMembers)): ?>
                                            <span style="font-weight: 700; font-size: 0.8rem; color: var(--primary-color);">
                                                👥 <?= count($panelMembers); ?> Member(s)
                                            </span>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php 
                                                    $chair = array_filter($panelMembers, fn($m) => $m['panel_role'] === 'chair');
                                                    if (!empty($chair)) {
                                                        $c = reset($chair);
                                                        echo 'Chair: ' . e($c['first_name'] . ' ' . $c['last_name']);
                                                    }
                                                ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.8rem;">No Panel Assigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($outcomeData): ?>
                                            <span class="badge-status badge-completed">
                                                <?= str_replace('_', ' ', strtoupper($outcomeData['outcome'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <?php if ($pStatus === 'final_submission_approved'): ?>
                                                <span class="badge-status badge-ready">Approved for Defense</span>
                                            <?php elseif ($pStatus === 'defense_scheduled'): ?>
                                                <span class="badge-status badge-scheduled">Defense Scheduled</span>
                                            <?php elseif ($pStatus === 'final_corrections'): ?>
                                                <span class="badge-status badge-corrections">Corrections Pending</span>
                                            <?php elseif ($pStatus === 'completed'): ?>
                                                <span class="badge-status badge-approved">🎉 Final Completed</span>
                                            <?php else: ?>
                                                <span class="badge-status" style="background: #f1f5f9; color: #475569;"><?= str_replace('_', ' ', $pStatus); ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                                            <?php if (!$isSuper): ?>
                                                <!-- Schedule Defense Button -->
                                                <button onclick="openScheduleModal(<?= $pId; ?>, '<?= e(addslashes($row['project_title'])); ?>', '<?= e($row['defense_date'] ?? ''); ?>', '<?= e($row['start_time'] ?? ''); ?>', '<?= e($row['end_time'] ?? ''); ?>', '<?= e(addslashes($row['venue'] ?? '')); ?>', '<?= e(addslashes($row['room_location'] ?? '')); ?>')" class="btn-sm" style="background: #1e40af; color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                                    📅 Schedule Defense
                                                </button>

                                                <!-- Panel Management Button -->
                                                <?php if ($dId > 0): ?>
                                                    <button onclick="openPanelModal(<?= $dId; ?>, '<?= e(addslashes($row['project_title'])); ?>')" class="btn-sm" style="background: #4f46e5; color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                                        👥 Manage Panel
                                                    </button>

                                                    <!-- Record Outcome Button -->
                                                    <button onclick="openOutcomeModal(<?= $dId; ?>, '<?= e(addslashes($row['project_title'])); ?>')" class="btn-sm" style="background: #059669; color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                                        📊 Record Result
                                                    </button>
                                                <?php endif; ?>

                                                <!-- Final Sign-off Completion Button -->
                                                <?php if (in_array($pStatus, ['defense_completed', 'final_corrections', 'approved'], true)): ?>
                                                    <button onclick="openSignoffModal(<?= $pId; ?>, '<?= e(addslashes($row['project_title'])); ?>')" class="btn-sm" style="background: #047857; color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                                                        🎉 Final Sign-off
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Read-Only View</span>
                                            <?php endif; ?>

                                            <a href="../projects/view.php?id=<?= $pId; ?>" style="font-size: 0.75rem; color: var(--accent-color); font-weight: 600; text-decoration: none;">
                                                View Project &rarr;
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Schedule Defense Modal -->
    <div id="scheduleModal" class="modal-backdrop">
        <div class="modal-card">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                📅 Schedule Oral Defense / Viva
            </h3>
            <p id="scheduleProjTitle" style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; font-weight: 600;"></p>

            <form action="../defense/schedule_defense.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                <input type="hidden" name="project_id" id="schedProjectId" value="">

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Defense Title</label>
                    <input type="text" name="defense_title" class="form-control" value="Final Oral Project Defense (Viva Voce)" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label class="form-label">Defense Date</label>
                        <input type="date" name="defense_date" id="schedDate" class="form-control" required>
                    </div>
                    <div>
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" id="schedStartTime" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label class="form-label">End Time (Optional)</label>
                        <input type="time" name="end_time" id="schedEndTime" class="form-control">
                    </div>
                    <div>
                        <label class="form-label">Venue / Building</label>
                        <input type="text" name="venue" id="schedVenue" class="form-control" placeholder="e.g. Faculty Auditorium" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Room / Hall Location</label>
                    <input type="text" name="room_location" id="schedRoom" class="form-control" placeholder="e.g. Room 102, Main Complex">
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label">Instructions for Candidate & Panel</label>
                    <textarea name="instructions" class="form-control" rows="3" placeholder="Additional guidelines, presentation time limit, slide submission..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeModal('scheduleModal')" class="btn-logout" style="border: 1px solid var(--border-color); background: #fff; color: var(--text-main);">Cancel</button>
                    <button type="submit" class="btn-primary" style="font-weight: 700;">Confirm Schedule</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Panel Management Modal -->
    <div id="panelModal" class="modal-backdrop">
        <div class="modal-card">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                👥 Manage Examination Panel
            </h3>
            <p id="panelProjTitle" style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; font-weight: 600;"></p>

            <form action="../defense/manage_panel.php" method="POST" style="margin-bottom: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="defense_id" id="panelDefenseId" value="">

                <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.75rem;">Add Panel Examiner</h4>
                
                <div class="form-group" style="margin-bottom: 0.75rem;">
                    <label class="form-label">Select System Examiner</label>
                    <select name="user_id" class="form-control">
                        <option value="">-- External Examiner (or select from system) --</option>
                        <?php foreach ($availablePanelUsers as $pu): ?>
                            <option value="<?= $pu['id']; ?>">
                                Prof./Dr. <?= e($pu['first_name'] . ' ' . $pu['last_name']); ?> (<?= e($pu['department'] ?: $pu['role_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label class="form-label">External Name (if non-system)</label>
                        <input type="text" name="external_name" class="form-control" placeholder="Dr. Jane Doe">
                    </div>
                    <div>
                        <label class="form-label">External Institution</label>
                        <input type="text" name="external_institution" class="form-control" placeholder="ABU Zaria / UNILAG">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Panel Designation / Role</label>
                    <select name="panel_role" class="form-control" required>
                        <option value="chair">Panel Chair</option>
                        <option value="internal_examiner" selected>Internal Examiner</option>
                        <option value="external_examiner">External Examiner</option>
                        <option value="supervisor_member">Supervisor Member</option>
                        <option value="observer">Observer</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; font-weight: 700;">+ Add Examiner to Panel</button>
            </form>

            <div style="display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeModal('panelModal')" class="btn-logout" style="border: 1px solid var(--border-color); background: #fff; color: var(--text-main);">Close Panel Manager</button>
            </div>
        </div>
    </div>

    <!-- Record Defense Outcome Modal -->
    <div id="outcomeModal" class="modal-backdrop">
        <div class="modal-card">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                📊 Record Defense Examination Result
            </h3>
            <p id="outcomeProjTitle" style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; font-weight: 600;"></p>

            <form action="../defense/record_outcome.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                <input type="hidden" name="defense_id" id="outcomeDefenseId" value="">

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Official Defense Decision / Outcome</label>
                    <select name="outcome" class="form-control" required>
                        <option value="passed">Passed (No Corrections Required)</option>
                        <option value="passed_with_minor_corrections" selected>Passed with Minor Corrections</option>
                        <option value="major_corrections_required">Major Corrections Required</option>
                        <option value="re_defense_required">Re-defense Required</option>
                        <option value="failed">Failed</option>
                        <option value="deferred">Deferred</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Decision Date</label>
                    <input type="date" name="decision_date" class="form-control" value="<?= date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group" style="margin-bottom: 1rem;">
                    <label class="form-label">Panel Overall Examination Remarks</label>
                    <textarea name="overall_remarks" class="form-control" rows="3" placeholder="General performance evaluation, presentation defense notes..."></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label">Post-Defense Corrections Required (Summary)</label>
                    <textarea name="corrections_required_summary" class="form-control" rows="3" placeholder="Specific correction points required by the examination panel..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeModal('outcomeModal')" class="btn-logout" style="border: 1px solid var(--border-color); background: #fff; color: var(--text-main);">Cancel</button>
                    <button type="submit" class="btn-primary" style="font-weight: 700; background: #059669;">Save Defense Outcome</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Final Sign-off Completion Modal -->
    <div id="signoffModal" class="modal-backdrop">
        <div class="modal-card">
            <h3 style="font-size: 1.25rem; font-weight: 700; color: #047857; margin-bottom: 0.5rem;">
                🎉 Grant Final Institutional Project Completion
            </h3>
            <p id="signoffProjTitle" style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem; font-weight: 600;"></p>

            <form action="../defense/final_signoff.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                <input type="hidden" name="project_id" id="signoffProjectId" value="">

                <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; font-size: 0.85rem;">
                    ℹ️ Granting final completion will verify all milestone completions, mark project status as <strong>COMPLETED</strong>, and archive final academic records for repository deposit.
                </div>

                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label class="form-label">Final Approval Remarks</label>
                    <textarea name="completion_remarks" class="form-control" rows="3" placeholder="Institutional approval notes, certificate clearing remarks..."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeModal('signoffModal')" class="btn-logout" style="border: 1px solid var(--border-color); background: #fff; color: var(--text-main);">Cancel</button>
                    <button type="submit" class="btn-primary" style="font-weight: 700; background: #047857;">Confirm Final Approval & Completion</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

    <script>
        function openScheduleModal(pId, title, date, stime, etime, venue, room) {
            document.getElementById('schedProjectId').value = pId;
            document.getElementById('scheduleProjTitle').innerText = title;
            document.getElementById('schedDate').value = date;
            document.getElementById('schedStartTime').value = stime;
            document.getElementById('schedEndTime').value = etime;
            document.getElementById('schedVenue').value = venue;
            document.getElementById('schedRoom').value = room;
            document.getElementById('scheduleModal').style.display = 'flex';
        }

        function openPanelModal(dId, title) {
            document.getElementById('panelDefenseId').value = dId;
            document.getElementById('panelProjTitle').innerText = title;
            document.getElementById('panelModal').style.display = 'flex';
        }

        function openOutcomeModal(dId, title) {
            document.getElementById('outcomeDefenseId').value = dId;
            document.getElementById('outcomeProjTitle').innerText = title;
            document.getElementById('outcomeModal').style.display = 'flex';
        }

        function openSignoffModal(pId, title) {
            document.getElementById('signoffProjectId').value = pId;
            document.getElementById('signoffProjTitle').innerText = title;
            document.getElementById('signoffModal').style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
    </script>
</body>
</html>
