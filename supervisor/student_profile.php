<?php
/**
 * Student Supervision Profile & Workspace
 * FUD RDM System - Phase 6: Advanced Supervision, Milestones & Progress Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';

// Enforce supervisor role
requireRole('supervisor');

$user   = currentUser();
$userId = (int)$user['id'];

$studentId = (int)($_GET['student_id'] ?? 0);
if ($studentId <= 0) {
    header('Location: students.php');
    exit;
}

// Security / IDOR: Ensure target student is actively assigned to current supervisor
$checkStmt = $pdo->prepare("
    SELECT ss.*, 
           u.first_name, u.last_name, u.email, u.phone, u.matric_number, u.department, u.faculty, u.academic_level,
           p.name AS programme_name
    FROM student_supervisors ss
    INNER JOIN users u ON ss.student_id = u.id
    LEFT JOIN programmes p ON u.programme_id = p.id
    WHERE ss.supervisor_id = :supervisor_id AND ss.student_id = :student_id AND ss.status = 'active'
    LIMIT 1
");
$checkStmt->execute([':supervisor_id' => $userId, ':student_id' => $studentId]);
$studentInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$studentInfo) {
    http_response_code(403);
    $_SESSION['access_error'] = 'Access Denied: You are not authorized to view the supervision profile for this student.';
    header('Location: students.php');
    exit;
}

// Fetch student's research project
$projStmt = $pdo->prepare("
    SELECT * FROM research_projects 
    WHERE owner_id = :student_id AND status != 'archived' 
    ORDER BY created_at DESC LIMIT 1
");
$projStmt->execute([':student_id' => $studentId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);

$projectData = null;
if ($project) {
    $projectId = (int)$project['id'];
    ensureDefaultProjectMilestones($pdo, $projectId);
    syncProjectMilestoneStatuses($pdo, $projectId);
    $projectData = getProjectSupervisionData($pdo, $projectId);
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervision Profile — <?= e($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?> | FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .profile-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 992px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }
        .profile-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }
        .info-group {
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px dashed #e2e8f0;
        }
        .info-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }
        .info-val {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        .stage-stepper {
            display: flex;
            gap: 0.5rem;
            overflow-x: auto;
            padding-bottom: 0.5rem;
            margin-top: 1rem;
        }
        .stage-step {
            flex: 1;
            min-width: 100px;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.6rem 0.5rem;
            text-align: center;
            font-size: 0.75rem;
        }
        .stage-step.done {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
            font-weight: 700;
        }
        .stage-step.active {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
            font-weight: 700;
        }
        .progress-bar-wrap {
            background: #e2e8f0;
            border-radius: 9999px;
            height: 12px;
            overflow: hidden;
            width: 100%;
            margin: 0.5rem 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
            transition: width 0.3s ease;
        }
        .badge-status {
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-open { background: #fef3c7; color: #92400e; }
        .badge-addressed { background: #dbeafe; color: #1e40af; }
        .badge-accepted { background: #d1fae5; color: #065f46; }
        .badge-pending { background: #f1f5f9; color: #475569; }
        .badge-in_progress { background: #eff6ff; color: #1d4ed8; }
        .badge-completed { background: #d1fae5; color: #047857; }
        .badge-overdue { background: #fee2e2; color: #b91c1c; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .data-table th, .data-table td {
            padding: 0.65rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }
        .data-table th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
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
                    <div class="user-affiliation"><?= e($user['department'] ?: 'Faculty Supervisor'); ?></div>
                </div>
                <span class="role-badge role-badge-supervisor">Supervisor</span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem;">
            <a href="students.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Student Roster
            </a>
        </div>

        <?php if ($flashSuccess): ?>
            <div style="background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.85rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; font-weight: 600;">
                ✓ <?= e($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 0.85rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.25rem; font-weight: 600;">
                ⚠ <?= e($flashError); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                    🎓 Supervision Workspace: <?= e($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?>
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Consolidated student research supervision dashboard, milestone tracking, submissions & correction management.
                </p>
            </div>
            <?php if ($project): ?>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="../messages/index.php?project_id=<?= (int)$project['id']; ?>&student_id=<?= (int)$studentId; ?>" class="btn-primary" style="display: inline-flex; align-items: center; gap: 0.4rem; text-decoration: none; padding: 0.6rem 1.25rem; font-weight: 700; background: var(--accent-color);">
                        💬 Message Student
                    </a>
                    <a href="../projects/view.php?id=<?= (int)$project['id']; ?>" class="btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.6rem 1.25rem; font-weight: 700;">
                        📂 Open Research Project File
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="profile-layout">
            <!-- Left Sidebar: Student & Assignment Meta -->
            <div>
                <!-- Student Info Card -->
                <div class="profile-card">
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem; border-bottom: 2px solid var(--accent-color); padding-bottom: 0.5rem;">
                        👤 Student Details
                    </h3>
                    <div class="info-group">
                        <div class="info-label">Full Name</div>
                        <div class="info-val"><?= e($studentInfo['first_name'] . ' ' . $studentInfo['last_name']); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Matric Number / Student ID</div>
                        <div class="info-val" style="font-family: monospace; color: var(--accent-color);"><?= e($studentInfo['matric_number'] ?: 'N/A'); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Email & Phone</div>
                        <div class="info-val" style="font-size: 0.85rem; color: var(--text-main);"><?= e($studentInfo['email']); ?></div>
                        <?php if ($studentInfo['phone']): ?>
                            <div style="font-size: 0.85rem; color: var(--text-muted);"><?= e($studentInfo['phone']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Department & Faculty</div>
                        <div class="info-val"><?= e($studentInfo['department'] ?: 'Unassigned'); ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($studentInfo['faculty'] ?: 'Faculty'); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Programme & Level</div>
                        <div class="info-val"><?= e($studentInfo['programme_name'] ?: 'B.Sc.'); ?> (<?= e($studentInfo['academic_level'] ?: 'Final Year'); ?>)</div>
                    </div>
                </div>

                <!-- Supervision Meta Card -->
                <div class="profile-card">
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem; border-bottom: 2px solid var(--accent-color); padding-bottom: 0.5rem;">
                        🤝 Supervision Context
                    </h3>
                    <div class="info-group">
                        <div class="info-label">Supervisor</div>
                        <div class="info-val"><?= e($user['name']); ?> (You)</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Supervision Role</div>
                        <div class="info-val"><?= e(ucfirst($studentInfo['supervision_type'])); ?> Supervisor</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Academic Session</div>
                        <div class="info-val"><?= e($studentInfo['academic_session']); ?></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">Assignment Date</div>
                        <div class="info-val"><?= date('M d, Y', strtotime($studentInfo['assigned_at'])); ?></div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Project Work, Stages, Milestones, Corrections & Submissions -->
            <div>
                <?php if (!$project): ?>
                    <div class="profile-card" style="text-align: center; padding: 3rem;">
                        <div style="font-size: 3rem; margin-bottom: 0.5rem;">📝</div>
                        <h3 style="font-size: 1.2rem; color: var(--primary-color); font-weight: 700;">No Project Registered Yet</h3>
                        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.5rem;">
                            <?= e($studentInfo['first_name']); ?> has not registered a research topic/project in the system yet.
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Project Overview & Progress Bar -->
                    <div class="profile-card">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.75rem;">
                            <div>
                                <span style="font-family: monospace; font-size: 0.8rem; font-weight: 700; color: var(--accent-color);"><?= e($project['project_code']); ?></span>
                                <h2 style="font-size: 1.35rem; color: var(--primary-color); font-weight: 700; margin-top: 0.2rem;">
                                    <?= e($project['title']); ?>
                                </h2>
                            </div>
                            <span class="badge-status badge-<?= e($project['status']); ?>" style="font-size: 0.85rem;">
                                <?= e(str_replace('_', ' ', strtoupper($project['status']))); ?>
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div style="margin-top: 1rem;">
                            <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 0.9rem;">
                                <span>Overall Project Progress</span>
                                <span style="color: #059669;"><?= (int)$projectData['progress_percentage']; ?>% Complete</span>
                            </div>
                            <div class="progress-bar-wrap">
                                <div class="progress-bar-fill" style="width: <?= (int)$projectData['progress_percentage']; ?>%;"></div>
                            </div>
                        </div>

                        <!-- Stages Stepper -->
                        <div class="stage-stepper">
                            <?php 
                            $chCount = (isset($project) && !empty($project['id'])) ? getProjectChapterCount($pdo, (int)$project['id']) : 5;
                            $stagesList = getChapterStageDefinitions($chCount);
                            $currStage = $projectData['current_stage_key'] ?? 'chapter_1';
                            $foundActive = false;
                            foreach ($stagesList as $key => $stDef):
                                $isDone = false;
                                if ($projectData['progress_percentage'] == 100 || (isset($projectData['milestones']) && isset($projectData['milestones'][$key]) && $projectData['milestones'][$key]['status'] === 'completed')) {
                                    $isDone = true;
                                }
                                $class = $isDone ? 'done' : ($key === $currStage ? 'active' : '');
                            ?>
                                <div class="stage-step <?= $class; ?>">
                                    <?= $isDone ? '✓ ' : ''; ?><?= e($stDef['short'] ?? $stDef['short_name'] ?? $stDef['label']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Phase 8: Final Readiness Checklist & Defense Authorization Card -->
                    <?php 
                    $readiness = checkFinalProjectReadiness($pdo, (int)$project['id']);
                    $defense = getProjectDefense($pdo, (int)$project['id']);
                    $panel = $defense ? getDefensePanel($pdo, (int)$defense['id']) : [];
                    $outcome = $defense ? getDefenseOutcome($pdo, (int)$defense['id']) : null;
                    ?>
                    <div class="profile-card" style="border-left: 4px solid var(--accent-color);">
                        <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>🎓 Final Project Readiness & Defense Authorization</span>
                            <?php if ($readiness['is_ready']): ?>
                                <span style="background: #dcfce7; color: #15803d; font-size: 0.8rem; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: 700;">
                                    ✅ <?= $readiness['ready_count']; ?> / <?= $readiness['total_count']; ?> Prerequisites Met
                                </span>
                            <?php else: ?>
                                <span style="background: #fef3c7; color: #92400e; font-size: 0.8rem; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: 700;">
                                    ⏳ <?= $readiness['ready_count']; ?> / <?= $readiness['total_count']; ?> Criteria Satisfied
                                </span>
                            <?php endif; ?>
                        </h3>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                            <?php foreach ($readiness['checklist'] as $cItem): ?>
                                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; display: flex; align-items: flex-start; gap: 0.75rem;">
                                    <div style="font-size: 1.25rem; font-weight: 700;">
                                        <?= $cItem['passed'] ? '✅' : '❌'; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 0.85rem; color: var(--primary-color);">
                                            <?= e($cItem['label']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                            <?= e($cItem['details']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Supervisor Final Review Action Form -->
                        <div style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1rem;">
                            <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                                ✍️ Supervisor Final Review & Defense Authorization
                            </h4>
                            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                                Review the student's full draft submission. Once satisfied, authorize the project for final oral defense.
                            </p>

                            <form action="../defense/supervisor_final_approval.php" method="POST">
                                <?= csrfField(); ?>
                                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">

                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <label class="form-label" style="font-weight: 700;">Supervisor Approval Decision</label>
                                    <select name="decision" class="form-control" required style="font-weight: 600;">
                                        <option value="approved" <?= in_array($project['status'], ['final_submission_approved', 'defense_scheduled', 'defense_completed', 'final_corrections', 'approved', 'completed']) ? 'selected' : ''; ?>>
                                            ✓ Approve Project for Final Defense / Viva
                                        </option>
                                        <option value="corrections_required" <?= $project['status'] === 'final_submission_corrections' ? 'selected' : ''; ?>>
                                            ⚠️ Request Final Draft Corrections
                                        </option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <label class="form-label" style="font-weight: 700;">Supervisor Final Remarks / Feedback</label>
                                    <textarea name="final_remarks" class="form-control" rows="3" placeholder="Provide final feedback, defense recommendations, or specific corrections required..."></textarea>
                                </div>

                                <button type="submit" class="btn-primary" style="font-weight: 700;">
                                    Submit Final Decision
                                </button>
                            </form>
                        </div>

                        <!-- Defense Details if Scheduled -->
                        <?php if ($defense): ?>
                            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-sm); padding: 1.25rem;">
                                <h4 style="font-size: 0.95rem; font-weight: 700; color: #1e40af; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                                    <span>📅 Scheduled Oral Defense Details</span>
                                    <span style="font-size: 0.75rem; text-transform: uppercase; background: #dbeafe; padding: 0.2rem 0.5rem; border-radius: 9999px;">
                                        <?= e($defense['status']); ?>
                                    </span>
                                </h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; font-size: 0.85rem; margin-bottom: 0.75rem;">
                                    <div><strong>Date:</strong> <?= date('M d, Y', strtotime($defense['defense_date'])); ?></div>
                                    <div><strong>Time:</strong> <?= e($defense['start_time']); ?> <?= $defense['end_time'] ? ' - ' . e($defense['end_time']) : ''; ?></div>
                                    <div><strong>Venue:</strong> <?= e($defense['venue']); ?> <?= $defense['room_location'] ? '(' . e($defense['room_location']) . ')' : ''; ?></div>
                                </div>
                                <?php if (!empty($panel)): ?>
                                    <div style="margin-top: 0.5rem; border-top: 1px dashed #93c5fd; padding-top: 0.5rem;">
                                        <strong style="font-size: 0.8rem; color: #1e40af;">Examination Panel:</strong>
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.25rem;">
                                            <?php foreach ($panel as $pm): ?>
                                                <span style="background: #ffffff; border: 1px solid #bfdbfe; font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 4px;">
                                                    <strong><?= e(ucfirst(str_replace('_', ' ', $pm['panel_role']))); ?>:</strong> 
                                                    <?= e($pm['first_name'] ? $pm['first_name'] . ' ' . $pm['last_name'] : $pm['external_name']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($outcome): ?>
                                    <div style="margin-top: 0.75rem; background: #ffffff; border: 1px solid #93c5fd; padding: 0.75rem; border-radius: 4px;">
                                        <strong style="font-size: 0.85rem; color: #047857;">Examination Outcome:</strong>
                                        <div style="font-weight: 700; color: #065f46; font-size: 0.9rem; text-transform: uppercase;">
                                            <?= str_replace('_', ' ', $outcome['outcome']); ?>
                                        </div>
                                        <?php if ($outcome['overall_remarks']): ?>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                <?= e($outcome['overall_remarks']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Milestones Workspace Section -->
                    <div class="profile-card">
                        <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                            🎯 Project Milestones & Due Dates
                        </h3>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                            Set due dates, update status, and manage milestone progression for this student project.
                        </p>

                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Milestone</th>
                                        <th>Target Due Date</th>
                                        <th>Status</th>
                                        <th>Completion Date</th>
                                        <th>Supervisor Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projectData['milestones'] as $m): 
                                        $mTitle = $m['title'] ?? $m['milestone_name'] ?? 'Milestone';
                                        $mComp  = $m['completed_at'] ?? $m['completion_date'] ?? null;
                                        $mNotes = $m['description'] ?? $m['milestone_notes'] ?? '';
                                    ?>
                                        <tr>
                                            <td style="font-weight: 700; color: var(--primary-color);">
                                                <?= e($mTitle); ?>
                                                <?php if (!empty($mNotes)): ?>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.25rem;">
                                                        📌 <?= e($mNotes); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= !empty($m['due_date']) ? date('M d, Y', strtotime($m['due_date'])) : '<span style="color:#94a3b8;">Not set</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="badge-status badge-<?= e($m['status'] ?? 'pending'); ?>">
                                                    <?= e(str_replace('_', ' ', ucfirst($m['status'] ?? 'pending'))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= !empty($mComp) ? date('M d, Y', strtotime($mComp)) : '&mdash;'; ?>
                                            </td>
                                            <td>
                                                <!-- Milestone Inline Action Form -->
                                                <form action="../supervision/milestone_manage.php" method="POST" style="display: flex; gap: 0.35rem; align-items: center;">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="milestone_id" value="<?= (int)$m['id']; ?>">
                                                    <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                                    
                                                    <input type="date" name="due_date" value="<?= e($m['due_date']); ?>" style="padding: 0.25rem; font-size: 0.75rem; border: 1px solid var(--border-color); border-radius: 4px; width: 115px;">
                                                    
                                                    <select name="status" style="padding: 0.25rem; font-size: 0.75rem; border: 1px solid var(--border-color); border-radius: 4px;">
                                                        <option value="pending" <?= $m['status']==='pending'?'selected':''; ?>>Pending</option>
                                                        <option value="in_progress" <?= $m['status']==='in_progress'?'selected':''; ?>>In Progress</option>
                                                        <option value="completed" <?= $m['status']==='completed'?'selected':''; ?>>Completed</option>
                                                        <option value="overdue" <?= $m['status']==='overdue'?'selected':''; ?>>Overdue</option>
                                                    </select>
                                                    
                                                    <button type="submit" class="btn-sm" style="background: var(--accent-color); color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                                        Save
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Correction Tracking Section -->
                    <div class="profile-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                                🛠 Structured Correction Items
                            </h3>
                            <?php 
                            $openCount = 0;
                            foreach ($projectData['corrections'] as $c) {
                                if ($c['status'] !== 'accepted') $openCount++;
                            }
                            ?>
                            <?php if ($openCount > 0): ?>
                                <span style="background: #fef3c7; color: #92400e; font-size: 0.8rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 9999px;">
                                    <?= $openCount; ?> Action Required
                                </span>
                            <?php endif; ?>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                            Track items raised during reviews to ensure student addresses all requested corrections before final sign-off.
                        </p>

                        <?php if (empty($projectData['corrections'])): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; text-align: center; color: var(--text-muted); border-radius: var(--radius-sm); font-size: 0.9rem;">
                                No correction items logged yet for this project.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Correction Title</th>
                                            <th>Details</th>
                                            <th>Version</th>
                                            <th>Status</th>
                                            <th>Student Response</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($projectData['corrections'] as $cor): ?>
                                            <tr>
                                                <td style="font-weight: 700; color: var(--primary-color);">
                                                    <?= e($cor['correction_title']); ?>
                                                </td>
                                                <td style="font-size: 0.8rem; color: var(--text-main); max-width: 250px;">
                                                    <?= nl2br(e($cor['correction_details'])); ?>
                                                </td>
                                                <td style="font-family: monospace; font-size: 0.8rem;">
                                                    v<?= (int)$cor['version_number']; ?>
                                                </td>
                                                <td>
                                                    <span class="badge-status badge-<?= e($cor['status']); ?>">
                                                        <?= e(ucfirst($cor['status'])); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size: 0.8rem; font-style: italic;">
                                                    <?= $cor['student_response'] ? e($cor['student_response']) : '<span style="color:#94a3b8;">Awaiting response</span>'; ?>
                                                </td>
                                                <td>
                                                    <?php if ($cor['status'] === 'addressed'): ?>
                                                        <form action="../supervision/correction_manage.php" method="POST">
                                                            <?= csrfField(); ?>
                                                            <input type="hidden" name="correction_id" value="<?= (int)$cor['id']; ?>">
                                                            <input type="hidden" name="student_id" value="<?= (int)$studentId; ?>">
                                                            <input type="hidden" name="action" value="accept">
                                                            <button type="submit" class="btn-sm" style="background: #059669; color: #fff; border: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                                                ✓ Mark Accepted
                                                            </button>
                                                        </form>
                                                    <?php elseif ($cor['status'] === 'accepted'): ?>
                                                        <span style="font-size: 0.75rem; color: #059669; font-weight: 700;">✓ Verified</span>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.75rem; color: #d97706; font-weight: 600;">Student fixing</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submissions & Reviews History -->
                    <div class="profile-card">
                        <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem;">
                            📄 Submissions & Review History
                        </h3>
                        <?php if (empty($projectData['submissions'])): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; text-align: center; color: var(--text-muted); border-radius: var(--radius-sm); font-size: 0.9rem;">
                                No document submissions received from this student yet.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Doc Type</th>
                                            <th>Version</th>
                                            <th>Submitted Date</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($projectData['submissions'] as $sub): ?>
                                            <tr>
                                                <td style="font-weight: 700; color: var(--primary-color);">
                                                    <?= e(str_replace('_', ' ', strtoupper($sub['document_type']))); ?>
                                                </td>
                                                <td style="font-family: monospace; font-weight: 700;">
                                                    v<?= (int)$sub['version_number']; ?>
                                                </td>
                                                <td>
                                                    <?= date('M d, Y H:i', strtotime($sub['submitted_at'])); ?>
                                                </td>
                                                <td>
                                                    <span class="badge-status badge-<?= e($sub['status']); ?>">
                                                        <?= e(str_replace('_', ' ', ucfirst($sub['status']))); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="../projects/view.php?id=<?= (int)$project['id']; ?>" class="btn-sm" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                        Review Submission &rarr;
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>
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
