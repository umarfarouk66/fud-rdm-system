<?php
/**
 * Supervisor Dashboard
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

$user = currentUser();
$userId = (int)$user['id'];
$accessError = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_error']);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);

// Fetch supervisor students overview using Phase 6 helper
$overviewData = getSupervisorStudentsOverview($pdo, $userId);

$assignedStudentsCount  = count($overviewData['students']);
$pendingSubmissionsList  = $overviewData['attention']['pending_reviews'];
$openCorrectionsList     = $overviewData['attention']['open_corrections'];
$overdueMilestonesList   = $overviewData['attention']['overdue_milestones'];
$activeStudentsList      = $overviewData['students'];

// Fetch defenses for supervisor's students
$defensesStmt = $pdo->prepare("
    SELECT d.*, rp.title AS project_title, rp.status AS project_status, u.first_name, u.last_name, u.matric_number, u.id AS student_id, rp.id AS project_id
    FROM project_defenses d
    INNER JOIN research_projects rp ON d.project_id = rp.id
    INNER JOIN users u ON rp.owner_id = u.id
    INNER JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
    WHERE ss.supervisor_id = :sup_id
    ORDER BY d.defense_date ASC
");
$defensesStmt->execute([':sup_id' => $userId]);
$myStudentDefenses = $defensesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .recent-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            margin-top: 0.75rem;
        }
        .recent-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
        }
        .recent-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .recent-table tr:last-child td {
            border-bottom: none;
        }
        .progress-bar-wrap {
            background: #e2e8f0;
            border-radius: 9999px;
            height: 8px;
            overflow: hidden;
            width: 100px;
            display: inline-block;
            vertical-align: middle;
            margin-right: 0.35rem;
        }
        .progress-bar-fill {
            height: 100%;
            background: #10b981;
        }
        .badge-attention {
            background: #fef3c7;
            color: #92400e;
            padding: 0.2rem 0.55rem;
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
            <a href="dashboard.php" class="btn-home-nav" style="text-decoration: none; color: #1e3a8a; display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 700; background: #ffffff; padding: 0.45rem 0.9rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); font-size: 0.85rem; margin-right: 0.5rem;" title="Go to Home Dashboard">
                <span>🏠 Home</span>
            </a>
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
                    <div class="user-affiliation">
                        <?= e($user['department'] ?: ($user['faculty'] ?: 'Faculty Supervisor')); ?>
                    </div>
                </div>
                <span class="role-badge role-badge-supervisor">Supervisor</span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Flash Notice if redirected -->
        <?php if (!empty($accessError)): ?>
            <div class="dash-alert dash-alert-danger">
                <span><?= e($accessError); ?></span>
            </div>
        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="welcome-card" style="background: linear-gradient(135deg, #78350f 0%, #92400e 100%);">
            <div class="welcome-text">
                <h1>Welcome, Prof./Dr. <?= e($user['last_name']); ?>!</h1>
                <p>Academic Project Supervision Workspace. Monitor assigned student research progress, evaluate chapter submissions and track correction resolution.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="students.php" style="display: inline-flex; align-items: center; background: #ffffff; color: #78350f; font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                    👥 My Assigned Students (<?= $assignedStudentsCount; ?>)
                </a>
                <a href="../projects/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none;">
                    📁 Research Projects
                </a>
                <a href="../messages/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.25); color: #ffffff; border: 1px solid rgba(255,255,255,0.5); font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none;">
                    💬 Messages & Chat
                </a>
                <a href="../reports/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.5rem; border-radius: var(--radius-sm); text-decoration: none;">
                    📊 Reports
                </a>
            </div>
        </div>

        <!-- Metric Cards Grid -->
        <div class="stats-grid">
            <a href="students.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-purple">🎓</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $assignedStudentsCount; ?></div>
                        <div class="stat-label">Assigned Students</div>
                    </div>
                </div>
            </a>

            <div class="stat-card" style="<?= count($pendingSubmissionsList) > 0 ? 'border-color: #f59e0b; background: #fffbebf5;' : ''; ?>">
                <div class="stat-icon-box icon-amber">📝</div>
                <div class="stat-info">
                    <div class="stat-value" style="<?= count($pendingSubmissionsList) > 0 ? 'color: #b45309;' : ''; ?>"><?= count($pendingSubmissionsList); ?></div>
                    <div class="stat-label">Submissions Awaiting Review</div>
                </div>
            </div>

            <div class="stat-card" style="<?= count($openCorrectionsList) > 0 ? 'border-color: #3b82f6; background: #eff6ff;' : ''; ?>">
                <div class="stat-icon-box icon-blue">🛠</div>
                <div class="stat-info">
                    <div class="stat-value" style="<?= count($openCorrectionsList) > 0 ? 'color: #1e40af;' : ''; ?>"><?= count($openCorrectionsList); ?></div>
                    <div class="stat-label">Pending Corrections</div>
                </div>
            </div>

            <div class="stat-card" style="<?= count($overdueMilestonesList) > 0 ? 'border-color: #ef4444; background: #fef2f2;' : ''; ?>">
                <div class="stat-icon-box icon-emerald">⚠️</div>
                <div class="stat-info">
                    <div class="stat-value" style="<?= count($overdueMilestonesList) > 0 ? 'color: #b91c1c;' : ''; ?>"><?= count($overdueMilestonesList); ?></div>
                    <div class="stat-label">Overdue Milestones</div>
                </div>
            </div>

            <div class="stat-card" style="<?= count($myStudentDefenses) > 0 ? 'border-color: #8b5cf6; background: #f5f3ff;' : ''; ?>">
                <div class="stat-icon-box" style="background: #ede9fe; color: #7c3aed;">🎓</div>
                <div class="stat-info">
                    <div class="stat-value" style="<?= count($myStudentDefenses) > 0 ? 'color: #6d28d9;' : ''; ?>"><?= count($myStudentDefenses); ?></div>
                    <div class="stat-label">Scheduled Defenses</div>
                </div>
            </div>
        </div>

        <!-- ATTENTION REQUIRED SECTION -->
        <?php if (!empty($pendingSubmissionsList) || !empty($openCorrectionsList) || !empty($overdueMilestonesList)): ?>
            <div class="content-card" style="border-left: 4px solid #f59e0b; margin-bottom: 2rem;">
                <h2 class="section-title" style="color: #b45309; margin-bottom: 0.75rem;">
                    ⚠️ Supervision Attention Required
                </h2>

                <!-- Awaiting Reviews -->
                <?php if (!empty($pendingSubmissionsList)): ?>
                    <div style="margin-bottom: 1.25rem;">
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                            📄 Submissions Pending Your Review (<?= count($pendingSubmissionsList); ?>)
                        </h4>
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Project Title</th>
                                    <th>Document Type</th>
                                    <th>Submitted Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingSubmissionsList as $ps): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($ps['first_name'] . ' ' . $ps['last_name']); ?></strong><br>
                                            <span style="font-family: monospace; font-size: 0.75rem; color: var(--text-muted);"><?= e($ps['matric_number']); ?></span>
                                        </td>
                                        <td><strong><?= e($ps['project_title']); ?></strong></td>
                                        <td>
                                            <span style="font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: #e0e7ff; color: #3730a3;">
                                                <?= e(str_replace('_', ' ', strtoupper($ps['document_type']))); ?> v<?= (int)$ps['version_number']; ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y H:i', strtotime($ps['submitted_at'])); ?></td>
                                        <td>
                                            <a href="../projects/view.php?id=<?= (int)$ps['project_id']; ?>" class="btn-submit" style="font-size: 0.75rem; padding: 0.3rem 0.65rem; text-decoration: none;">
                                                Evaluate Submission &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Corrections Pending Supervisor Verification -->
                <?php if (!empty($openCorrectionsList)): ?>
                    <div style="margin-bottom: 1.25rem;">
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                            🛠 Addressed Corrections Requiring Verification (<?= count($openCorrectionsList); ?>)
                        </h4>
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Correction Item</th>
                                    <th>Student Response</th>
                                    <th>Addressed Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($openCorrectionsList as $cor): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($cor['first_name'] . ' ' . $cor['last_name']); ?></strong>
                                        </td>
                                        <td><strong><?= e($cor['correction_title']); ?></strong></td>
                                        <td style="font-size: 0.8rem; font-style: italic;">
                                            <?= e($cor['student_response']); ?>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($cor['addressed_at'])); ?></td>
                                        <td>
                                            <a href="student_profile.php?student_id=<?= (int)$cor['student_id']; ?>" class="btn-submit" style="font-size: 0.75rem; padding: 0.3rem 0.65rem; text-decoration: none; background: #059669;">
                                                Verify & Accept &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Overdue Milestones -->
                <?php if (!empty($overdueMilestonesList)): ?>
                    <div>
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: #b91c1c; margin-bottom: 0.5rem;">
                            ⚠️ Overdue Student Project Milestones (<?= count($overdueMilestonesList); ?>)
                        </h4>
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Milestone</th>
                                    <th>Target Due Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overdueMilestonesList as $om): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e($om['first_name'] . ' ' . $om['last_name']); ?></strong>
                                        </td>
                                        <td><strong><?= e($om['milestone_name']); ?></strong></td>
                                        <td style="color: #b91c1c; font-weight: 700;">
                                            <?= date('M d, Y', strtotime($om['due_date'])); ?>
                                        </td>
                                        <td>
                                            <a href="student_profile.php?student_id=<?= (int)$om['student_id']; ?>" style="color: var(--accent-color); font-weight: 700; text-decoration: none; font-size: 0.8rem;">
                                                Update Milestone &rarr;
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

        <!-- ASSIGNED STUDENTS ROSTER -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <h2 class="section-title" style="margin-bottom: 0;">My Assigned Students Roster</h2>
                <a href="students.php" style="color: var(--accent-color); text-decoration: none; font-size: 0.85rem; font-weight: 600;">
                    Full Roster Directory &rarr;
                </a>
            </div>

            <?php if (empty($activeStudentsList)): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">No student researchers are currently assigned to your supervision roster.</p>
            <?php else: ?>
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>Matric / ID</th>
                            <th>Student Name</th>
                            <th>Department / Level</th>
                            <th>Project Title</th>
                            <th>Research Progress</th>
                            <th>Supervision Workspace</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeStudentsList as $as): 
                            $stId = (int)$as['student_id'];
                            $projId = (int)($as['project_id'] ?? 0);
                            $progressPct = 0;
                            $stageLabel = 'No Project';
                            if ($projId > 0) {
                                $pData = getProjectSupervisionData($pdo, $projId);
                                $progressPct = (int)$pData['progress_percentage'];
                                $stageLabel = $pData['current_stage_label'];
                            }
                        ?>
                            <tr>
                                <td><span style="font-family: monospace; font-weight: 700; color: var(--accent-color);"><?= e($as['matric_number'] ?: 'N/A'); ?></span></td>
                                <td><strong><?= e($as['first_name'] . ' ' . $as['last_name']); ?></strong></td>
                                <td><?= e($as['department'] ?: 'Unassigned'); ?> (<?= e($as['academic_level'] ?: 'Final'); ?>)</td>
                                <td>
                                    <?php if ($projId > 0): ?>
                                        <strong><?= e($as['project_title']); ?></strong>
                                    <?php else: ?>
                                        <span style="font-style: italic; color: var(--text-muted);">Topic Not Registered</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($projId > 0): ?>
                                        <div class="progress-bar-wrap">
                                            <div class="progress-bar-fill" style="width: <?= $progressPct; ?>%;"></div>
                                        </div>
                                        <strong style="font-size: 0.8rem; color: #059669;"><?= $progressPct; ?>%</strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($stageLabel); ?></div>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="student_profile.php?student_id=<?= $stId; ?>" style="color: var(--accent-color); font-weight: 700; text-decoration: none; font-size: 0.85rem;">
                                        Open Profile &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
