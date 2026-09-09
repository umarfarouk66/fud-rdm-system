<?php
/**
 * Student-Supervisor Assignment & Workload Management Console
 * FUD RDM & Project Supervision System - Step 16 (Phase 3)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/admin_helpers.php';

// Enforce administrator role (Super Admin gets read-only monitoring access)
requireRole('admin');

$adminUser = currentUser();
$adminId   = (int)$adminUser['id'];

$feedbackMessage = $_SESSION['admin_success'] ?? '';
$errorMessage    = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Current active tab
$tab = sanitize_input($_GET['tab'] ?? 'assigned');
if (!in_array($tab, ['assigned', 'unassigned', 'workload', 'history'], true)) {
    $tab = 'assigned';
}

// Handle Form Submissions (Assign, Reassign, End Assignment)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission(); // Server-side read-only guard for Super Admin

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (invalid CSRF token).';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Assign or Reassign Supervisor
        if ($action === 'assign_supervisor') {
            $studentId      = (int)($_POST['student_id'] ?? 0);
            $supervisorId   = (int)($_POST['supervisor_id'] ?? 0);
            $sessionName    = sanitize_input($_POST['academic_session'] ?? '2025/2026');
            $type           = sanitize_input($_POST['supervision_type'] ?? 'primary');
            $reason         = sanitize_input($_POST['reason'] ?? '');

            if ($studentId <= 0 || $supervisorId <= 0) {
                $errorMessage = 'Please select both a valid Student and Supervisor.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Verify Student exists
                    $sStmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email FROM users u INNER JOIN roles r ON u.role_id = r.id WHERE u.id = ? LIMIT 1");
                    $sStmt->execute([$studentId]);
                    $student = $sStmt->fetch(PDO::FETCH_ASSOC);

                    // Verify Supervisor exists
                    $vStmt = $pdo->prepare("
                        SELECT u.id, u.first_name, u.last_name, u.email, r.name AS role_name, sp.max_capacity, sp.staff_id
                        FROM users u 
                        INNER JOIN roles r ON u.role_id = r.id 
                        LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
                        WHERE u.id = ? LIMIT 1
                    ");
                    $vStmt->execute([$supervisorId]);
                    $supervisor = $vStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$student) {
                        $errorMessage = 'Selected student account was not found.';
                        $pdo->rollBack();
                    } elseif (!$supervisor || strtolower($supervisor['role_name']) !== 'supervisor') {
                        $errorMessage = 'Selected user is not designated as an active Supervisor.';
                        $pdo->rollBack();
                    } else {
                        $studentName    = $student['first_name'] . ' ' . $student['last_name'];
                        $supervisorName = $supervisor['first_name'] . ' ' . $supervisor['last_name'];

                        // Check existing active assignments for student in this session
                        $currStmt = $pdo->prepare("
                            SELECT ss.id, ss.supervisor_id, u.first_name, u.last_name 
                            FROM student_supervisors ss
                            INNER JOIN users u ON ss.supervisor_id = u.id
                            WHERE ss.student_id = :sid AND ss.academic_session = :session AND ss.status = 'active' AND ss.supervision_type = :type
                            LIMIT 1
                        ");
                        $currStmt->execute([':sid' => $studentId, ':session' => $sessionName, ':type' => $type]);
                        $existingActive = $currStmt->fetch(PDO::FETCH_ASSOC);

                        if ($existingActive) {
                            if ((int)$existingActive['supervisor_id'] === $supervisorId) {
                                $errorMessage = "Student '{$studentName}' is already actively assigned to Supervisor {$supervisorName} for {$sessionName}.";
                                $pdo->rollBack();
                            } else {
                                // Reassignment Workflow: Mark existing active assignment as 'reassigned'
                                $oldSupervisorName = $existingActive['first_name'] . ' ' . $existingActive['last_name'];

                                $reassignStmt = $pdo->prepare("
                                    UPDATE student_supervisors 
                                    SET status = 'reassigned', reassignment_reason = :reason, updated_at = CURRENT_TIMESTAMP 
                                    WHERE id = :id
                                ");
                                $reassignStmt->execute([
                                    ':reason' => $reason ?: "Reassigned to {$supervisorName} by Administrator",
                                    ':id'     => $existingActive['id']
                                ]);

                                // Insert new assignment record
                                $insStmt = $pdo->prepare("
                                    INSERT INTO student_supervisors 
                                        (student_id, supervisor_id, assigned_by, academic_session, supervision_type, status, reassignment_reason) 
                                    VALUES 
                                        (:student_id, :supervisor_id, :assigned_by, :session, :type, 'active', :reason)
                                ");
                                $insStmt->execute([
                                    ':student_id'    => $studentId,
                                    ':supervisor_id' => $supervisorId,
                                    ':assigned_by'   => $adminId,
                                    ':session'       => $sessionName,
                                    ':type'          => $type,
                                    ':reason'        => $reason ?: "Reassigned from {$oldSupervisorName}"
                                ]);

                                log_audit(
                                    $pdo,
                                    'supervisor_reassigned',
                                    'student_supervisors',
                                    (int)$pdo->lastInsertId(),
                                    "Administrator '{$adminUser['name']}' reassigned student '{$studentName}' from Supervisor '{$oldSupervisorName}' to Supervisor '{$supervisorName}'"
                                );

                                // Send notifications
                                createNotification($pdo, $studentId, 'supervision', 'Supervisor Reassigned', "Your research project supervisor has been updated to Prof./Dr. {$supervisorName}.", 'user', $studentId);
                                createNotification($pdo, $supervisorId, 'supervision', 'New Student Assigned', "Student {$studentName} has been assigned to you for supervision ({$sessionName}).", 'user', $studentId);
                                createNotification($pdo, (int)$existingActive['supervisor_id'], 'supervision', 'Student Reassigned', "Supervision assignment for student {$studentName} has ended (Reassigned).", 'user', $studentId);

                                $pdo->commit();
                                $_SESSION['admin_success'] = "Student '{$studentName}' successfully reassigned from {$oldSupervisorName} to Supervisor {$supervisorName}.";
                                header("Location: assign_supervisor.php?tab=assigned");
                                exit;
                            }
                        } else {
                            // Fresh Assignment
                            $insStmt = $pdo->prepare("
                                INSERT INTO student_supervisors 
                                    (student_id, supervisor_id, assigned_by, academic_session, supervision_type, status, reassignment_reason) 
                                VALUES 
                                    (:student_id, :supervisor_id, :assigned_by, :session, :type, 'active', :reason)
                            ");
                            $insStmt->execute([
                                ':student_id'    => $studentId,
                                ':supervisor_id' => $supervisorId,
                                ':assigned_by'   => $adminId,
                                ':session'       => $sessionName,
                                ':type'          => $type,
                                ':reason'        => $reason ?: 'Initial supervision assignment'
                            ]);

                            log_audit(
                                $pdo,
                                'supervisor_assigned',
                                'student_supervisors',
                                (int)$pdo->lastInsertId(),
                                "Administrator '{$adminUser['name']}' assigned student '{$studentName}' to Supervisor '{$supervisorName}'"
                            );

                            createNotification($pdo, $studentId, 'supervision', 'Supervisor Assigned', "Prof./Dr. {$supervisorName} has been assigned as your research project supervisor.", 'user', $studentId);
                            createNotification($pdo, $supervisorId, 'supervision', 'New Student Assigned', "Student {$studentName} has been assigned to your supervision roster ({$sessionName}).", 'user', $studentId);

                            $pdo->commit();
                            $_SESSION['admin_success'] = "Student '{$studentName}' successfully assigned to Supervisor {$supervisorName}.";
                            header("Location: assign_supervisor.php?tab=assigned");
                            exit;
                        }
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Assignment Error: " . $e->getMessage());
                    $errorMessage = 'A database error occurred while recording supervision assignment.';
                }
            }
        }
        // 2. End Supervision Assignment
        elseif ($action === 'end_assignment') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $reason       = sanitize_input($_POST['reason'] ?? '');

            if ($assignmentId <= 0) {
                $errorMessage = 'Invalid assignment record specified.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT ss.id, ss.student_id, ss.supervisor_id,
                               u1.first_name AS s_fname, u1.last_name AS s_lname,
                               u2.first_name AS v_fname, u2.last_name AS v_lname
                        FROM student_supervisors ss
                        INNER JOIN users u1 ON ss.student_id = u1.id
                        INNER JOIN users u2 ON ss.supervisor_id = u2.id
                        WHERE ss.id = ? AND ss.status = 'active'
                        LIMIT 1
                    ");
                    $stmt->execute([$assignmentId]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($record) {
                        $upStmt = $pdo->prepare("UPDATE student_supervisors SET status = 'ended', reassignment_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $upStmt->execute([$reason ?: 'Ended by Administrator', $assignmentId]);

                        $sName = $record['s_fname'] . ' ' . $record['s_lname'];
                        $vName = $record['v_fname'] . ' ' . $record['v_lname'];

                        log_audit($pdo, 'supervision_ended', 'student_supervisors', $assignmentId, "Administrator '{$adminUser['name']}' ended supervision assignment between '{$sName}' and Supervisor '{$vName}'");
                        createNotification($pdo, (int)$record['student_id'], 'supervision', 'Supervision Concluded', "Your project supervision relationship with Prof./Dr. {$vName} has concluded.", 'user', (int)$record['student_id']);
                        createNotification($pdo, (int)$record['supervisor_id'], 'supervision', 'Supervision Concluded', "Supervision assignment for student {$sName} has concluded.", 'user', (int)$record['student_id']);

                        $feedbackMessage = "Supervision assignment between '{$sName}' and {$vName} marked as Ended.";
                    } else {
                        $errorMessage = 'Active assignment record not found.';
                    }
                } catch (PDOException $e) {
                    $errorMessage = 'Error ending assignment: ' . $e->getMessage();
                }
            }
        }
    }
}

// Search and Filter Parameters
$search       = trim($_GET['q'] ?? '');
$deptFilter   = (int)($_GET['dept_id'] ?? 0);
$sessionFilter= trim($_GET['session'] ?? '');

// Fetch Data for Modals & Dropdowns
$supervisorsList = $pdo->query("
    SELECT 
        u.id, u.first_name, u.last_name, u.email, u.department, u.faculty,
        sp.staff_id, sp.max_capacity,
        COUNT(CASE WHEN ss.status = 'active' THEN 1 END) AS active_count
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id AND r.name = 'supervisor'
    LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
    LEFT JOIN student_supervisors ss ON u.id = ss.supervisor_id AND ss.status = 'active'
    WHERE u.status = 'active'
    GROUP BY u.id
    ORDER BY u.first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sessionsList = $pdo->query("SELECT * FROM academic_sessions ORDER BY is_current DESC, session_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$currentSessionName = !empty($sessionsList) ? $sessionsList[0]['session_name'] : '2025/2026';

// ---------------------------------------------------------------------
// Query Tab 1: Active Assigned Students
// ---------------------------------------------------------------------
$assignedStudents = [];
if ($tab === 'assigned') {
    $where = ["ss.status = 'active'"];
    $params = [];

    if ($search !== '') {
        $where[] = "(u1.first_name LIKE :q OR u1.last_name LIKE :q OR u1.email LIKE :q OR u1.matric_number LIKE :q OR u2.first_name LIKE :q OR u2.last_name LIKE :q OR sp.staff_id LIKE :q)";
        $params[':q'] = "%{$search}%";
    }

    $whereSql = implode(" AND ", $where);
    $aStmt = $pdo->prepare("
        SELECT 
            ss.id AS assignment_id, ss.academic_session, ss.supervision_type, ss.assigned_at,
            u1.id AS student_id, u1.first_name AS s_fname, u1.last_name AS s_lname, u1.email AS s_email, u1.matric_number, u1.department AS s_dept, u1.academic_level,
            u2.id AS supervisor_id, u2.first_name AS v_fname, u2.last_name AS v_lname, u2.email AS v_email, sp.staff_id
        FROM student_supervisors ss
        INNER JOIN users u1 ON ss.student_id = u1.id
        INNER JOIN users u2 ON ss.supervisor_id = u2.id
        LEFT JOIN supervisor_profiles sp ON u2.id = sp.user_id
        WHERE {$whereSql}
        ORDER BY ss.assigned_at DESC
    ");
    $aStmt->execute($params);
    $assignedStudents = $aStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------
// Query Tab 2: Unassigned Students
// ---------------------------------------------------------------------
$unassignedStudents = [];
if ($tab === 'unassigned') {
    $where = ["r.name = 'researcher'", "u.status = 'active'", "u.id NOT IN (SELECT student_id FROM student_supervisors WHERE status = 'active')"];
    $params = [];

    if ($search !== '') {
        $where[] = "(u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q OR u.matric_number LIKE :q OR u.department LIKE :q)";
        $params[':q'] = "%{$search}%";
    }

    $whereSql = implode(" AND ", $where);
    $uStmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.matric_number, u.department, u.faculty, u.academic_level, p.name AS programme_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        LEFT JOIN programmes p ON u.programme_id = p.id
        WHERE {$whereSql}
        ORDER BY u.created_at DESC
    ");
    $uStmt->execute($params);
    $unassignedStudents = $uStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------
// Query Tab 3: Supervisor Workload Statistics
// ---------------------------------------------------------------------
$workloadData = [];
if ($tab === 'workload') {
    $wStmt = $pdo->query("
        SELECT 
            u.id AS supervisor_id, u.first_name, u.last_name, u.email, u.department, u.faculty,
            sp.staff_id, COALESCE(sp.max_capacity, 10) AS max_capacity,
            COUNT(CASE WHEN ss.status = 'active' THEN 1 END) AS active_students,
            COUNT(CASE WHEN ss.status = 'completed' THEN 1 END) AS completed_students
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id AND r.name = 'supervisor'
        LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_supervisors ss ON u.id = ss.supervisor_id
        GROUP BY u.id
        ORDER BY active_students DESC, u.first_name ASC
    ");
    $workloadData = $wStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------
// Query Tab 4: Assignment History
// ---------------------------------------------------------------------
$historyLogs = [];
if ($tab === 'history') {
    $hStmt = $pdo->query("
        SELECT 
            ss.id, ss.academic_session, ss.supervision_type, ss.status, ss.reassignment_reason, ss.assigned_at, ss.updated_at,
            u1.first_name AS s_fname, u1.last_name AS s_lname, u1.matric_number,
            u2.first_name AS v_fname, u2.last_name AS v_lname, sp.staff_id,
            u3.first_name AS a_fname, u3.last_name AS a_lname
        FROM student_supervisors ss
        INNER JOIN users u1 ON ss.student_id = u1.id
        INNER JOIN users u2 ON ss.supervisor_id = u2.id
        LEFT JOIN users u3 ON ss.assigned_by = u3.id
        LEFT JOIN supervisor_profiles sp ON u2.id = sp.user_id
        ORDER BY ss.updated_at DESC
        LIMIT 100
    ");
    $historyLogs = $hStmt->fetchAll(PDO::FETCH_ASSOC);
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student-Supervisor Assignment & Workload — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .tabs-header {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 0.65rem 1.25rem;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-muted);
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.15s ease;
        }
        .tab-btn.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: #f8fafc;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
        }
        .action-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table th {
            background: #f8fafc;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            font-weight: 700;
            color: var(--primary-color);
        }
        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .modal-drawer {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            z-index: 999;
            align-items: center;
            justify-content: center;
        }
        .modal-drawer.active { display: flex; }
        .modal-box {
            background: #ffffff;
            border-radius: var(--radius-md);
            width: 90%;
            max-width: 600px;
            padding: 2rem;
            box-shadow: var(--shadow-lg);
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
                    <div class="user-affiliation"><?= isSuperAdmin() ? 'Institutional Monitor' : 'System Administrator'; ?></div>
                </div>
                <span class="role-badge role-badge-admin" style="<?= isSuperAdmin() ? 'background: #4338ca; color: #ffffff;' : ''; ?>">
                    <?= isSuperAdmin() ? 'Super Admin' : 'Admin'; ?>
                </span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Navigation Breadcrumbs -->
        <div style="margin-bottom: 1.25rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
        </div>

        <!-- Flash Notices -->
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

        <?php if (isSuperAdmin()): ?>
            <div class="dash-alert" style="background: #e0e7ff; border-color: #6366f1; color: #312e81; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">👁️</span>
                <div>
                    <strong>Super Admin Read-Only Mode:</strong> Supervision assignments and workload statistics are visible in monitoring mode. Form submissions are disabled server-side.
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                    👨‍🏫 Student-Supervisor Assignment & Workload
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Link student researchers to academic supervisors, monitor departmental capacity, and track assignment history.
                </p>
            </div>

            <?php if (!isSuperAdmin()): ?>
                <button type="button" class="btn-action" onclick="openAssignModal(0);" style="background: var(--primary-color); color: #ffffff; padding: 0.6rem 1.25rem; font-weight: 700; border-radius: var(--radius-sm); border: none; cursor: pointer;">
                    ➕ Assign New Student
                </button>
            <?php endif; ?>
        </div>

        <!-- Navigation Tabs -->
        <div class="tabs-header">
            <a href="assign_supervisor.php?tab=assigned" class="tab-btn <?= ($tab === 'assigned') ? 'active' : ''; ?>">
                ✅ Active Assignments (<?= count($assignedStudents); ?>)
            </a>
            <a href="assign_supervisor.php?tab=unassigned" class="tab-btn <?= ($tab === 'unassigned') ? 'active' : ''; ?>">
                ⚠️ Unassigned Students (<?= count($unassignedStudents); ?>)
            </a>
            <a href="assign_supervisor.php?tab=workload" class="tab-btn <?= ($tab === 'workload') ? 'active' : ''; ?>">
                📊 Supervisor Workload
            </a>
            <a href="assign_supervisor.php?tab=history" class="tab-btn <?= ($tab === 'history') ? 'active' : ''; ?>">
                📜 Assignment History
            </a>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="assign_supervisor.php" style="margin-bottom: 1.5rem; display: flex; gap: 0.75rem;">
            <input type="hidden" name="tab" value="<?= e($tab); ?>">
            <input type="text" name="q" value="<?= e($search); ?>" placeholder="Search student name, matric number, supervisor..." style="flex: 1; padding: 0.55rem 0.85rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.9rem;">
            <button type="submit" class="btn-action" style="background: #334155; color: #ffffff; padding: 0.55rem 1.25rem; font-weight: 600; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                Search
            </button>
            <?php if ($search !== ''): ?>
                <a href="assign_supervisor.php?tab=<?= e($tab); ?>" style="align-self: center; font-size: 0.85rem; color: var(--text-muted);">Reset</a>
            <?php endif; ?>
        </form>

        <!-- TAB 1: ACTIVE ASSIGNED STUDENTS -->
        <?php if ($tab === 'assigned'): ?>
            <div class="action-card">
                <?php if (empty($assignedStudents)): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2rem;">No active student-supervisor assignments found matching your search.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Matric No / Dept</th>
                                <th>Assigned Supervisor</th>
                                <th>Staff ID / Dept</th>
                                <th>Session / Type</th>
                                <th>Assigned Date</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignedStudents as $a): ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--primary-color);"><?= e($a['s_fname'] . ' ' . $a['s_lname']); ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($a['s_email']); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 700; color: var(--accent-color);"><?= e($a['matric_number'] ?: '—'); ?></span>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($a['s_dept'] ?: 'Unspecified'); ?></div>
                                    </td>
                                    <td>
                                        <strong style="color: #059669;">Prof./Dr. <?= e($a['v_fname'] . ' ' . $a['v_lname']); ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($a['v_email']); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-family: monospace; font-size: 0.8rem;"><?= e($a['staff_id'] ?: '—'); ?></span>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 0.15rem 0.5rem; background: #e0e7ff; color: #3730a3; border-radius: 9999px; display: inline-block;">
                                            <?= e($a['academic_session']); ?> (<?= e(ucfirst($a['supervision_type'])); ?>)
                                        </span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?= date('M d, Y', strtotime($a['assigned_at'])); ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <?php if (!isSuperAdmin()): ?>
                                            <button type="button" onclick="openAssignModal(<?= (int)$a['student_id']; ?>, '<?= e($a['s_fname'] . ' ' . $a['s_lname']); ?>');" style="padding: 0.35rem 0.65rem; border-radius: var(--radius-sm); font-size: 0.8rem; font-weight: 600; border: 1px solid var(--accent-color); background: #ffffff; color: var(--accent-color); cursor: pointer; margin-right: 0.35rem;">
                                                ✏️ Reassign
                                            </button>
                                            <form method="POST" action="assign_supervisor.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to end this supervision assignment?');">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="end_assignment">
                                                <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id']; ?>">
                                                <button type="submit" style="padding: 0.35rem 0.65rem; border-radius: var(--radius-sm); font-size: 0.8rem; font-weight: 600; border: 1px solid #dc2626; background: #ffffff; color: #dc2626; cursor: pointer;">
                                                    🛑 End
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">Read-Only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- TAB 2: UNASSIGNED STUDENTS -->
        <?php if ($tab === 'unassigned'): ?>
            <div class="action-card">
                <?php if (empty($unassignedStudents)): ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 2rem;">🎉 Great news! All registered student researchers currently have an active supervisor assigned.</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Matric Number</th>
                                <th>Email</th>
                                <th>Department / Faculty</th>
                                <th>Programme / Level</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unassignedStudents as $u): ?>
                                <tr>
                                    <td><strong style="color: var(--primary-color);"><?= e($u['first_name'] . ' ' . $u['last_name']); ?></strong></td>
                                    <td><span style="font-family: monospace; font-weight: 700; color: #d97706;"><?= e($u['matric_number'] ?: 'Unassigned Matric'); ?></span></td>
                                    <td><a href="mailto:<?= e($u['email']); ?>" style="color: var(--accent-color);"><?= e($u['email']); ?></a></td>
                                    <td>
                                        <div><?= e($u['department'] ?: 'Unspecified Department'); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($u['faculty'] ?: 'Unspecified Faculty'); ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($u['programme_name'] ?: 'General Research'); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($u['academic_level'] ?: 'Level Unset'); ?></div>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if (!isSuperAdmin()): ?>
                                            <button type="button" onclick="openAssignModal(<?= (int)$u['id']; ?>, '<?= e($u['first_name'] . ' ' . $u['last_name']); ?>');" style="padding: 0.4rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.8rem; font-weight: 700; background: #059669; color: #ffffff; border: none; cursor: pointer;">
                                                ➕ Assign Supervisor
                                            </button>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">Read-Only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- TAB 3: SUPERVISOR WORKLOAD -->
        <?php if ($tab === 'workload'): ?>
            <div class="action-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Supervisor Name</th>
                            <th>Staff ID</th>
                            <th>Department</th>
                            <th>Active Students</th>
                            <th>Max Capacity</th>
                            <th>Capacity Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($workloadData)): ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">No active supervisors designated in the system yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($workloadData as $w): 
                                $active = (int)$w['active_students'];
                                $cap    = (int)$w['max_capacity'];
                                $pct    = ($cap > 0) ? min(100, round(($active / $cap) * 100)) : 0;
                            ?>
                                <tr>
                                    <td><strong style="color: var(--primary-color);">Prof./Dr. <?= e($w['first_name'] . ' ' . $w['last_name']); ?></strong></td>
                                    <td><span style="font-family: monospace; font-weight: 700;"><?= e($w['staff_id'] ?: '—'); ?></span></td>
                                    <td><?= e($w['department'] ?: ($w['faculty'] ?: 'Faculty Supervisor')); ?></td>
                                    <td><strong style="font-size: 1.1rem; color: #059669;"><?= $active; ?></strong> active student(s)</td>
                                    <td><?= $cap; ?> max slots</td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <div style="flex: 1; background: #e2e8f0; height: 8px; border-radius: 9999px; overflow: hidden; max-width: 120px;">
                                                <div style="height: 100%; width: <?= $pct; ?>%; background: <?= $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#d97706' : '#059669'); ?>;"></div>
                                            </div>
                                            <span style="font-size: 0.75rem; font-weight: 700; color: <?= $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#d97706' : '#059669'); ?>;">
                                                <?= $pct >= 100 ? 'Full' : ($pct >= 80 ? 'Near Capacity' : 'Available'); ?> (<?= $pct; ?>%)
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- TAB 4: ASSIGNMENT HISTORY -->
        <?php if ($tab === 'history'): ?>
            <div class="action-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Supervisor</th>
                            <th>Session / Type</th>
                            <th>Status</th>
                            <th>Reason / Notes</th>
                            <th>Assigned By</th>
                            <th>Date Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historyLogs)): ?>
                            <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No historical supervision logs recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historyLogs as $h): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($h['s_fname'] . ' ' . $h['s_lname']); ?></strong>
                                        <div style="font-size: 0.75rem; font-family: monospace; color: var(--text-muted);"><?= e($h['matric_number']); ?></div>
                                    </td>
                                    <td>Prof./Dr. <?= e($h['v_fname'] . ' ' . $h['v_lname']); ?></td>
                                    <td><?= e($h['academic_session']); ?> (<?= e(ucfirst($h['supervision_type'])); ?>)</td>
                                    <td>
                                        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; padding: 0.15rem 0.5rem; border-radius: 9999px; background: <?= $h['status'] === 'active' ? '#d1fae5; color: #065f46;' : ($h['status'] === 'reassigned' ? '#fef3c7; color: #92400e;' : '#f1f5f9; color: #475569;'); ?>">
                                            <?= e(ucfirst($h['status'])); ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?= e($h['reassignment_reason'] ?: '—'); ?></td>
                                    <td style="font-size: 0.8rem;"><?= e($h['a_fname'] ? $h['a_fname'] . ' ' . $h['a_lname'] : 'System'); ?></td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);"><?= date('M d, Y H:i', strtotime($h['updated_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </main>

    <!-- ASSIGNMENT MODAL DRAWER -->
    <div id="assignModal" class="modal-drawer">
        <div class="modal-box">
            <h2 style="font-size: 1.35rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                👨‍🏫 Assign Student Supervisor
            </h2>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem;">
                Establish an official research supervision relationship between an enrolled student and a designated supervisor.
            </p>

            <form method="POST" action="assign_supervisor.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                <input type="hidden" name="action" value="assign_supervisor">

                <div class="form-row" style="margin-bottom: 1rem;">
                    <label for="modal_student_id">Select Student Researcher *</label>
                    <select id="modal_student_id" name="student_id" required style="padding: 0.55rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); width: 100%;">
                        <option value="">-- Choose Student --</option>
                        <?php 
                        $allStudents = $pdo->query("SELECT u.id, u.first_name, u.last_name, u.matric_number, u.department FROM users u INNER JOIN roles r ON u.role_id = r.id WHERE r.name = 'researcher' AND u.status = 'active' ORDER BY u.first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($allStudents as $st): 
                        ?>
                            <option value="<?= (int)$st['id']; ?>">
                                <?= e($st['first_name'] . ' ' . $st['last_name']); ?> <?= $st['matric_number'] ? ' (' . e($st['matric_number']) . ')' : ''; ?> — <?= e($st['department']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row" style="margin-bottom: 1rem;">
                    <label for="modal_supervisor_id">Select Academic Supervisor *</label>
                    <select id="modal_supervisor_id" name="supervisor_id" required style="padding: 0.55rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); width: 100%;">
                        <option value="">-- Choose Supervisor --</option>
                        <?php foreach ($supervisorsList as $sv): ?>
                            <option value="<?= (int)$sv['id']; ?>">
                                Prof./Dr. <?= e($sv['first_name'] . ' ' . $sv['last_name']); ?> (<?= e($sv['department'] ?: 'Faculty'); ?>) — Active Students: <?= (int)$sv['active_count']; ?>/<?= (int)($sv['max_capacity'] ?: 10); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-row">
                        <label for="modal_session">Academic Session *</label>
                        <select id="modal_session" name="academic_session" required style="padding: 0.55rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <?php foreach ($sessionsList as $ss): ?>
                                <option value="<?= e($ss['session_name']); ?>" <?= !empty($ss['is_current']) ? 'selected' : ''; ?>>
                                    <?= e($ss['session_name']); ?> <?= !empty($ss['is_current']) ? '(Current)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="modal_type">Supervision Role *</label>
                        <select id="modal_type" name="supervision_type" required style="padding: 0.55rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <option value="primary">Primary Supervisor</option>
                            <option value="co_supervisor">Co-Supervisor</option>
                            <option value="advisor">Academic Advisor</option>
                        </select>
                    </div>
                </div>

                <div class="form-row" style="margin-bottom: 1.5rem;">
                    <label for="modal_reason">Assignment Notes / Reassignment Reason</label>
                    <input type="text" id="modal_reason" name="reason" placeholder="Optional notes for audit log..." style="padding: 0.55rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); width: 100%;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                    <button type="button" onclick="closeAssignModal();" style="padding: 0.6rem 1.25rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: #ffffff; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" style="padding: 0.6rem 1.5rem; border-radius: var(--radius-sm); border: none; background: var(--primary-color); color: #ffffff; font-weight: 700; cursor: pointer;">
                        Save Supervision Assignment
                    </button>
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
        function openAssignModal(studentId, studentName) {
            var modal = document.getElementById('assignModal');
            if (studentId > 0) {
                var select = document.getElementById('modal_student_id');
                if (select) select.value = studentId;
            }
            modal.classList.add('active');
        }
        function closeAssignModal() {
            var modal = document.getElementById('assignModal');
            modal.classList.remove('active');
        }
    </script>
</body>
</html>
