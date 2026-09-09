<?php
/**
 * Research Project Detailed View
 * RDM Information System - Step 5, 6 & 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';
require_once __DIR__ . '/../dmp/dmp_helpers.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';
require_once __DIR__ . '/../repository/repository_helpers.php';

// Allow public viewing of research projects
$isGuest = !isLoggedIn();
$user = $isGuest ? null : currentUser();
$userId = $user ? (int)$user['id'] : 0;
$systemRole = $user ? $user['role'] : 'guest';

$projectId = (int)($_GET['id'] ?? 0);

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid research project identifier.';
    header('Location: index.php');
    exit;
}

// Fetch project details
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email,
            u.institution AS owner_institution,
            u.department AS owner_department
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        WHERE p.id = :project_id
        LIMIT 1
    ");
    $stmt->execute([':project_id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'The requested research project was not found.';
        header('Location: index.php');
        exit;
    }

    // Determine current user's role in this project
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce view authorization
    if (!canViewProject($projectRole, $systemRole, $project['status'] ?? 'active', (int)$project['owner_id'], $userId)) {
        $_SESSION['project_error'] = 'Access denied: You do not have permission to view this project.';
        header('Location: index.php');
        exit;
    }

    // Handle POST action for updating Chapter Structure (5 vs 7 Chapters)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_chapter_structure') {
        requireAuth();
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token).';
            header("Location: view.php?id={$projectId}");
            exit;
        }
        if (isSuperAdmin()) {
            http_response_code(403);
            die('Access Denied: Super Admin is strictly read-only.');
        }

        $newCount = (int)($_POST['chapter_count'] ?? 5);
        $newCount = ($newCount === 7) ? 7 : 5;

        $isOwner = ($userId === (int)$project['owner_id']);
        $isSup   = isSupervisorOfStudent($pdo, $userId, (int)$project['owner_id']);
        $isAdmin = (strtolower($systemRole) === 'admin');

        if ($isOwner || $isSup || $isAdmin || $canEdit) {
            $upd = $pdo->prepare("UPDATE research_projects SET chapter_count = :cnt WHERE id = :id");
            $upd->execute([':cnt' => $newCount, ':id' => $projectId]);

            ensureDefaultProjectMilestones($pdo, $projectId, true);
            syncProjectMilestoneStatuses($pdo, $projectId);

            $fmtName = ($newCount === 7) ? "7-Chapter Format (Extended)" : "5-Chapter Format (Standard)";
            $_SESSION['project_success'] = "Research chapter structure updated to {$fmtName}.";
            header("Location: view.php?id={$projectId}#academic-supervision");
            exit;
        } else {
            $_SESSION['project_error'] = 'Access Denied: You do not have permission to modify chapter structure.';
            header("Location: view.php?id={$projectId}");
            exit;
        }
    }

    // Permission flags
    $canEdit    = canEditProject($projectRole, $systemRole);
    $canDelete  = canDeleteProject($projectRole, $systemRole);
    $canMembers = canManageMembers($projectRole, $systemRole);

    // Fetch team members
    $memberStmt = $pdo->prepare("
        SELECT 
            pm.id AS member_record_id,
            pm.role AS project_role,
            pm.joined_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.department,
            u.institution
        FROM project_members pm
        INNER JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = :project_id
        ORDER BY 
            CASE pm.role 
                WHEN 'owner' THEN 1
                WHEN 'manager' THEN 2
                WHEN 'editor' THEN 3
                WHEN 'uploader' THEN 4
                WHEN 'viewer' THEN 5
                ELSE 6 
            END,
            pm.joined_at ASC
    ");
    $memberStmt->execute([':project_id' => $projectId]);
    $teamMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch associated DMP (Step 6)
    $dmpStmt = $pdo->prepare("SELECT * FROM data_management_plans WHERE project_id = :project_id LIMIT 1");
    $dmpStmt->execute([':project_id' => $projectId]);
    $projectDmp = $dmpStmt->fetch(PDO::FETCH_ASSOC);

    $canEditDmpFlag = $projectDmp ? canEditDmp($projectRole, $systemRole, $projectDmp['status']) : false;

    // Fetch associated Datasets (Step 7)
    $datasetStmt = $pdo->prepare("
        SELECT 
            d.*,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name
        FROM datasets d
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.project_id = :project_id
        ORDER BY d.created_at DESC
    ");
    $datasetStmt->execute([':project_id' => $projectId]);
    $rawDatasets = $datasetStmt->fetchAll(PDO::FETCH_ASSOC);
    $projectDatasets = array_values(array_filter($rawDatasets, function($ds) use ($projectRole, $systemRole, $userId) {
        return canViewDataset($projectRole, $systemRole, $ds['access_level'], (int)$ds['owner_id'], $userId);
    }));

    // Fetch Academic Supervision Data (Phase 4)
    $supervisionData = getProjectSupervisionData($pdo, $projectId);
    $assignedSupervisor = $supervisionData['supervisor'];
    $topicRecord         = $supervisionData['topic'];
    $proposalSub         = $supervisionData['proposal'];
    $proposalVersions    = $supervisionData['proposal_versions'];
    $proposalReviews     = $supervisionData['proposal_reviews'];

    $isProjectOwner      = ($userId === (int)$project['owner_id']);
    $isAssignedSupervisor= $assignedSupervisor ? ((int)$assignedSupervisor['supervisor_id'] === $userId) : false;

} catch (PDOException $e) {
    error_log("Project View Query Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while loading the project.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['project_success'] ?? null;
$errorMsg   = $_SESSION['project_error'] ?? null;
unset($_SESSION['project_success'], $_SESSION['project_error']);

// Role badge class helper
function getMemberRoleBadge(string $role): string {
    switch (strtolower(trim($role))) {
        case 'owner':
            return '<span class="member-badge badge-owner">Project Lead / Owner</span>';
        case 'manager':
            return '<span class="member-badge badge-manager">Project Manager</span>';
        case 'editor':
            return '<span class="member-badge badge-editor">Editor / Contributor</span>';
        case 'uploader':
            return '<span class="member-badge badge-uploader">Data Uploader</span>';
        case 'viewer':
        default:
            return '<span class="member-badge badge-viewer">Viewer / Observer</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($project['title']); ?> — Project View — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .view-header {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .project-code-tag {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
            display: inline-block;
        }
        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-header {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            transition: all 0.15s ease;
        }
        .btn-header:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background-color: var(--primary-light);
        }
        .btn-header-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-header-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-header-danger {
            color: #dc2626;
            background-color: #fef2f2;
            border-color: #fecaca;
        }
        .btn-header-danger:hover {
            background-color: #dc2626;
            color: #ffffff;
        }
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            box-shadow: var(--shadow-sm);
        }
        .section-heading {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.6rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.25rem;
        }
        .info-item {
            margin-bottom: 0.75rem;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        .info-value {
            font-size: 0.95rem;
            color: var(--text-main);
            line-height: 1.5;
        }
        .text-block {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            margin-top: 0.25rem;
        }
        .member-badge {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 9999px;
            text-transform: uppercase;
        }
        .badge-owner { background-color: #fee2e2; color: #991b1b; }
        .badge-manager { background-color: #fef3c7; color: #92400e; }
        .badge-editor { background-color: #e0e7ff; color: #3730a3; }
        .badge-uploader { background-color: #d1fae5; color: #065f46; }
        .badge-viewer { background-color: #f1f5f9; color: #475569; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .data-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
        }
        .data-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .data-table tr:last-child td {
            border-bottom: none;
        }
        .dmp-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <header class="dash-navbar">
        <a href="index.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <?php if (!$isGuest && $user): ?>
                <div class="user-badge-container">
                    <div>
                        <div class="user-name"><?= e($user['name']); ?></div>
                        <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                    </div>
                    <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
                </div>
                <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
            <?php else: ?>
                <a href="../public/index.php" class="btn-logout" style="background: transparent; color: var(--primary-color);">Home</a>
                <a href="../public/login.php" class="btn-logout" style="background: var(--primary-color); color: #ffffff; margin-right: 0.35rem;">Sign In</a>
                <a href="../public/register.php" class="btn-logout" style="background: var(--accent-color); color: #ffffff;">Register</a>
            <?php endif; ?>
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

        <!-- Breadcrumb / Back button -->
        <div style="margin-bottom: 1rem;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Research Projects Directory
            </a>
        </div>

        <!-- Project Header Card -->
        <div class="view-header">
            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($project['project_code']); ?></span>
                    <span style="margin-left: 0.5rem; text-transform: uppercase; font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 9999px; background: #e0e7ff; color: #3730a3;">
                        <?= e($project['status']); ?>
                    </span>
                </div>
                <div class="header-actions">
                    <?php if ($canEdit): ?>
                        <a href="edit.php?id=<?= (int)$project['id']; ?>" class="btn-header btn-header-primary">
                            ✏️ Edit Project
                        </a>
                    <?php endif; ?>

                    <?php if ($canMembers): ?>
                        <a href="members.php?id=<?= (int)$project['id']; ?>" class="btn-header">
                            👥 Manage Team
                        </a>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                        <form action="delete.php" method="POST" style="display: inline;" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete this project? All associated metadata, plans and datasets will be removed.');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                            <button type="submit" class="btn-header btn-header-danger">
                                🗑️ Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <h1 style="font-size: 1.85rem; color: var(--primary-color); font-weight: 700; line-height: 1.25; margin-bottom: 0.75rem;">
                <?= e($project['title']); ?>
            </h1>

            <div style="font-size: 0.9rem; color: var(--text-muted); display: flex; gap: 1.5rem; flex-wrap: wrap;">
                <div><strong>Lead Investigator:</strong> <?= e($project['owner_first_name'] . ' ' . $project['owner_last_name']); ?></div>
                <div><strong>Department:</strong> <?= e($project['department']); ?></div>
                <div><strong>Faculty:</strong> <?= e($project['faculty']); ?></div>
            </div>
        </div>

        <!-- Section: Academic Supervision & Submissions (Phase 4 Workflow) -->
        <div class="section-card" style="border-left: 4px solid var(--accent-color);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                <div>
                    <h2 class="section-heading" style="margin-bottom: 0.25rem; border-bottom: none; padding-bottom: 0;">
                        🎓 Academic Supervision & Submissions Lifecycle
                    </h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                        Track topic review approval, research proposal versioning and supervisor evaluations
                    </p>
                </div>
                <div>
                    <?= formatSupervisionStatusBadge($project['status']); ?>
                </div>
            </div>

            <!-- Active Supervisor Info Header -->
            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; margin-bottom: 1.5rem;">
                <?php if ($assignedSupervisor): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Assigned Supervisor:</span>
                            <strong style="font-size: 1rem; color: var(--primary-color); margin-left: 0.35rem;">
                                Prof./Dr. <?= e($assignedSupervisor['first_name'] . ' ' . $assignedSupervisor['last_name']); ?>
                            </strong>
                            <?php if ($assignedSupervisor['staff_id']): ?>
                                <span style="font-family: monospace; font-size: 0.8rem; color: var(--text-muted); margin-left: 0.5rem;">
                                    (Staff ID: <?= e($assignedSupervisor['staff_id']); ?>)
                                </span>
                            <?php endif; ?>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem;">
                                🏛️ <?= e($assignedSupervisor['department_name'] ?: 'Faculty Supervisor'); ?> &bull; ✉️ <?= e($assignedSupervisor['email']); ?>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <a href="../messages/index.php?project_id=<?= (int)$project['id']; ?>" class="btn-sm" style="background: var(--accent-color); color: #fff; text-decoration: none; padding: 0.3rem 0.75rem; border-radius: 4px; font-weight: 700; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                                💬 Message Supervisor
                            </a>
                            <span style="background: #d1fae5; color: #065f46; font-weight: 700; font-size: 0.75rem; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">
                                Active Assignment
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="color: #b45309; background: #fffbebf5; border: 1px solid #fde68a; padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.9rem; display: flex; align-items: center; gap: 0.5rem;">
                        <span>⚠️</span>
                        <div>
                            <strong>Supervisor Not Yet Assigned:</strong> An academic supervisor must be assigned by the Administrator before topic or proposal submissions can be processed.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sequential Research Progress Tracker Bar & Percentage (Phase 6) -->
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--primary-color); text-transform: uppercase;">
                        📈 Overall Research Project Progress
                    </div>
                    <div style="font-size: 0.95rem; font-weight: 700; color: #059669;">
                        <?= (int)$supervisionData['progress_percentage']; ?>% Complete
                    </div>
                </div>
                <div style="background: #e2e8f0; border-radius: 9999px; height: 12px; overflow: hidden; width: 100%; margin-bottom: 1rem;">
                    <div style="height: 100%; background: linear-gradient(90deg, #3b82f6, #10b981); width: <?= (int)$supervisionData['progress_percentage']; ?>%;"></div>
                </div>
                <div style="display: flex; gap: 0.4rem; overflow-x: auto; padding-bottom: 0.5rem;">
                    <?php 
                    $progressStages = $supervisionData['progress_stages'];
                    foreach ($progressStages as $psIndex => $psStage): 
                        $state = $psStage['state'];
                        $bg = '#f1f5f9; color: #64748b; border: 1px solid #e2e8f0;';
                        $icon = '○';
                        if ($state === 'approved') {
                            $bg = '#d1fae5; color: #065f46; border: 1px solid #a7f3d0;';
                            $icon = '✓';
                        } elseif ($state === 'corrections') {
                            $bg = '#fef3c7; color: #b45309; border: 1px solid #fcd34d;';
                            $icon = '⚠️';
                        } elseif ($state === 'in_progress') {
                            $bg = '#dbeafe; color: #1e40af; border: 1px solid #93c5fd;';
                            $icon = '⏳';
                        }
                    ?>
                        <div style="flex: 1; min-width: 90px; background: <?= $bg; ?>; padding: 0.5rem 0.4rem; border-radius: var(--radius-sm); text-align: center; font-size: 0.75rem; font-weight: 700; white-space: nowrap;">
                            <div><?= $icon; ?> <?= e($psStage['name']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Project Milestones & Target Due Dates Panel (Phase 6) -->
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.75rem;">
                    🎯 Project Milestones & Supervision Schedule
                </h3>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Milestone</th>
                                <th>Target Due Date</th>
                                <th>Status</th>
                                <th>Completion Date</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supervisionData['milestones'] as $m): 
                                $mTitle = $m['title'] ?? $m['milestone_name'] ?? 'Milestone';
                                $mComp  = $m['completed_at'] ?? $m['completion_date'] ?? null;
                                $mNotes = $m['description'] ?? $m['milestone_notes'] ?? '';
                            ?>
                                <tr>
                                    <td style="font-weight: 700; color: var(--primary-color);"><?= e($mTitle); ?></td>
                                    <td><?= !empty($m['due_date']) ? date('M d, Y', strtotime($m['due_date'])) : '<span style="color:#94a3b8;">Not set</span>'; ?></td>
                                    <td>
                                        <span class="badge-status badge-<?= e($m['status']); ?>" style="padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                            <?= e(str_replace('_', ' ', ucfirst($m['status']))); ?>
                                        </span>
                                    </td>
                                    <td><?= !empty($mComp) ? date('M d, Y', strtotime($mComp)) : '&mdash;'; ?></td>
                                    <td style="font-size: 0.8rem; color: var(--text-muted);"><?= e($mNotes ?: 'None'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Correction Tracking Panel (Phase 6) -->
            <?php if (!empty($supervisionData['corrections'])): ?>
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                        🛠 Structured Correction Items Log
                    </h3>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Correction Title</th>
                                    <th>Details</th>
                                    <th>Version</th>
                                    <th>Status</th>
                                    <th>Student Fix Response</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supervisionData['corrections'] as $cor): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--primary-color);"><?= e($cor['correction_title']); ?></td>
                                        <td style="font-size: 0.8rem; max-width: 250px;"><?= nl2br(e($cor['correction_details'])); ?></td>
                                        <td style="font-family: monospace;">v<?= (int)$cor['version_number']; ?></td>
                                        <td>
                                            <span class="badge-status badge-<?= e($cor['status']); ?>" style="padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                                <?= e(ucfirst($cor['status'])); ?>
                                            </span>
                                        </td>
                                        <td style="font-size: 0.8rem; font-style: italic;">
                                            <?= $cor['student_response'] ? e($cor['student_response']) : '<span style="color:#94a3b8;">Awaiting response</span>'; ?>
                                        </td>
                                        <td>
                                            <?php if ($isProjectOwner && $cor['status'] === 'open' && !isSuperAdmin()): ?>
                                                <form action="../supervision/correction_manage.php" method="POST" style="display: flex; gap: 0.35rem; align-items: center;">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="correction_id" value="<?= (int)$cor['id']; ?>">
                                                    <input type="hidden" name="action" value="address">
                                                    <input type="text" name="student_response" placeholder="Brief response note..." required style="padding: 0.25rem; font-size: 0.75rem; border: 1px solid var(--border-color); border-radius: 4px; width: 140px;">
                                                    <button type="submit" class="btn-sm" style="background: var(--primary-color); color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                                        Submit Fix
                                                    </button>
                                                </form>
                                            <?php elseif (($isAssignedSupervisor || strtolower($systemRole) === 'admin') && $cor['status'] === 'addressed' && !isSuperAdmin()): ?>
                                                <form action="../supervision/correction_manage.php" method="POST">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="correction_id" value="<?= (int)$cor['id']; ?>">
                                                    <input type="hidden" name="action" value="accept">
                                                    <button type="submit" class="btn-sm" style="background: #059669; color: #fff; border: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                                        ✓ Accept Fix
                                                    </button>
                                                </form>
                                            <?php elseif ($cor['status'] === 'accepted'): ?>
                                                <span style="font-size: 0.75rem; color: #059669; font-weight: 700;">✓ Accepted</span>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: var(--text-muted);">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Phase 8: Final Project Readiness, Oral Defense & Viva Examination Panel -->
            <?php 
            $readiness = checkFinalProjectReadiness($pdo, $projectId);
            $defenseData = getProjectDefense($pdo, $projectId);
            $panelList   = $defenseData ? getDefensePanel($pdo, (int)$defenseData['id']) : [];
            $outcomeData = $defenseData ? getDefenseOutcome($pdo, (int)$defenseData['id']) : null;
            ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 1.5rem; border-left: 4px solid var(--accent-color);">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <span>🎓 Final Readiness & Defense (Viva Voce) Registry</span>
                    <?php if ($readiness['is_ready']): ?>
                        <span style="background: #dcfce7; color: #15803d; font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: 700;">
                            ✅ <?= $readiness['ready_count']; ?> / <?= $readiness['total_count']; ?> Readiness Checklist Complete
                        </span>
                    <?php else: ?>
                        <span style="background: #fef3c7; color: #92400e; font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: 700;">
                            ⏳ <?= $readiness['ready_count']; ?> / <?= $readiness['total_count']; ?> Readiness Items Complete
                        </span>
                    <?php endif; ?>
                </h3>

                <!-- Readiness Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.85rem; margin-bottom: 1.25rem;">
                    <?php foreach ($readiness['checklist'] as $cItem): ?>
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; display: flex; align-items: center; gap: 0.65rem;">
                            <span style="font-size: 1.1rem;"><?= $cItem['passed'] ? '✅' : '❌'; ?></span>
                            <div>
                                <div style="font-weight: 700; font-size: 0.8rem; color: var(--primary-color);"><?= e($cItem['label']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($cItem['details']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Defense Schedule Card -->
                <?php if ($defenseData): ?>
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: #1e40af; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>📅 Scheduled Oral Defense Information</span>
                            <span style="font-size: 0.75rem; text-transform: uppercase; background: #dbeafe; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: 700;">
                                <?= e($defenseData['status']); ?>
                            </span>
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.85rem; font-size: 0.85rem; margin-bottom: 0.75rem;">
                            <div><strong>Defense Title:</strong> <?= e($defenseData['defense_title']); ?></div>
                            <div><strong>Defense Date:</strong> <?= date('F d, Y', strtotime($defenseData['defense_date'])); ?></div>
                            <div><strong>Time Slot:</strong> <?= e($defenseData['start_time']); ?> <?= $defenseData['end_time'] ? ' - ' . e($defenseData['end_time']) : ''; ?></div>
                            <div><strong>Venue / Room:</strong> <?= e($defenseData['venue']); ?> <?= $defenseData['room_location'] ? '(' . e($defenseData['room_location']) . ')' : ''; ?></div>
                        </div>
                        <?php if (!empty($defenseData['instructions'])): ?>
                            <div style="font-size: 0.8rem; color: #1e3a8a; background: #ffffff; padding: 0.5rem 0.75rem; border-radius: 4px; border-left: 3px solid #3b82f6;">
                                💡 <strong>Instructions:</strong> <?= e($defenseData['instructions']); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Examination Panel Members -->
                        <?php if (!empty($panelList)): ?>
                            <div style="margin-top: 0.85rem; border-top: 1px dashed #93c5fd; padding-top: 0.75rem;">
                                <strong style="font-size: 0.85rem; color: #1e40af;">🏛️ Examination Panel Members:</strong>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.35rem;">
                                    <?php foreach ($panelList as $pm): ?>
                                        <div style="background: #ffffff; border: 1px solid #bfdbfe; font-size: 0.8rem; padding: 0.35rem 0.65rem; border-radius: 4px;">
                                            <strong style="color: var(--primary-color);"><?= e(ucfirst(str_replace('_', ' ', $pm['panel_role']))); ?>:</strong> 
                                            <?= e($pm['first_name'] ? $pm['first_name'] . ' ' . $pm['last_name'] : $pm['external_name']); ?>
                                            <?php if ($pm['external_institution']): ?>
                                                <span style="font-size: 0.75rem; color: var(--text-muted);">(<?= e($pm['external_institution']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Examination Outcome Card -->
                <?php if ($outcomeData): ?>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-sm); padding: 1.25rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: #065f46; margin-bottom: 0.5rem;">
                            📊 Official Defense Examination Result
                        </h4>
                        <div style="font-weight: 800; font-size: 1.1rem; color: #047857; text-transform: uppercase; margin-bottom: 0.5rem;">
                            Outcome: <?= str_replace('_', ' ', $outcomeData['outcome']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #065f46;">
                            <strong>Decision Date:</strong> <?= date('F d, Y', strtotime($outcomeData['decision_date'])); ?>
                        </div>
                        <?php if (!empty($outcomeData['overall_remarks'])): ?>
                            <div style="font-size: 0.85rem; margin-top: 0.5rem; color: #064e3b;">
                                <strong>Overall Panel Remarks:</strong> <?= e($outcomeData['overall_remarks']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($outcomeData['corrections_required_summary'])): ?>
                            <div style="margin-top: 0.5rem; background: #ffffff; border: 1px solid #a7f3d0; padding: 0.75rem; border-radius: 4px; font-size: 0.85rem; color: #92400e;">
                                ⚠️ <strong>Required Post-Defense Corrections:</strong><br>
                                <?= nl2br(e($outcomeData['corrections_required_summary'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Phase 9: Institutional Repository Submission & Publication Gateway -->
            <?php 
            $repoEligibility = checkRepositoryEligibility($pdo, $projectId);
            $repoRecord      = getProjectRepositoryRecord($pdo, $projectId);
            ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 1.5rem; border-left: 4px solid #059669;">
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <span>📚 Institutional Research Repository Gateway</span>
                    <?php if ($repoRecord): ?>
                        <span class="badge-status badge-<?= e($repoRecord['status']); ?>" style="font-size: 0.85rem; padding: 0.25rem 0.75rem;">
                            Repository: <?= e(str_replace('_', ' ', strtoupper($repoRecord['status']))); ?>
                        </span>
                    <?php elseif ($repoEligibility['is_eligible']): ?>
                        <span style="background: #dcfce7; color: #15803d; font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: 700;">
                            ✅ Eligible for Repository Submission
                        </span>
                    <?php else: ?>
                        <span style="background: #fee2e2; color: #b91c1c; font-size: 0.8rem; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: 700;">
                            ⛔ Academic Approval Pending (<?= $repoEligibility['eligible_count']; ?>/<?= $repoEligibility['total_count']; ?>)
                        </span>
                    <?php endif; ?>
                </h3>

                <!-- Eligibility Checklist Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.85rem; margin-bottom: 1.25rem;">
                    <?php foreach ($repoEligibility['checklist'] as $cItem): ?>
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; display: flex; align-items: center; gap: 0.65rem;">
                            <span style="font-size: 1.1rem;"><?= $cItem['passed'] ? '✅' : '❌'; ?></span>
                            <div>
                                <div style="font-weight: 700; font-size: 0.8rem; color: var(--primary-color);"><?= e($cItem['label']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($cItem['details']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Case 1: Published Record Available -->
                <?php if ($repoRecord && $repoRecord['status'] === 'published'): ?>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: #065f46; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>🎉 Officially Published in FUD Research Data Repository</span>
                            <span style="font-size: 0.75rem; text-transform: uppercase; background: #059669; color: #fff; padding: 0.2rem 0.6rem; border-radius: 9999px; font-weight: 700;">
                                <?= e($repoRecord['access_level']); ?> Access
                            </span>
                        </h4>

                        <!-- Persistent Identifier (PID) -->
                        <?php if (!empty($repoRecord['identifiers'])): ?>
                            <div style="margin-bottom: 0.75rem;">
                                <strong style="font-size: 0.85rem; color: #065f46;">Persistent Identifier (PID):</strong>
                                <?php foreach ($repoRecord['identifiers'] as $pid): ?>
                                    <span style="font-family: monospace; font-weight: 700; background: #ffffff; border: 1px solid #a7f3d0; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.85rem; margin-left: 0.35rem;">
                                        <?= e($pid['identifier_type']); ?>: <?= e($pid['identifier_value']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display: flex; gap: 0.75rem; margin-top: 0.75rem;">
                            <a href="../datasets/view.php?id=<?= $repoRecord['id']; ?>" class="btn-sm" style="background: #059669; color: #ffffff; text-decoration: none; padding: 0.4rem 0.85rem; border-radius: 4px; font-weight: 700; font-size: 0.8rem;">
                                🌐 Open Public Repository Record Page &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Citations Card -->
                    <?php if (!empty($repoRecord['citations'])): ?>
                        <div style="margin-top: 1rem;">
                            <strong style="font-size: 0.85rem; color: var(--primary-color);">📖 Scholarly Citation Formats:</strong>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0.75rem; margin-top: 0.5rem;">
                                <?php foreach (array_slice($repoRecord['citations'], 0, 3) as $cVal): ?>
                                    <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 0.65rem; border-radius: var(--radius-sm); font-size: 0.75rem;">
                                        <strong><?= e($cVal['style']); ?>:</strong>
                                        <div style="font-family: monospace; background: #ffffff; padding: 0.4rem; border-radius: 4px; border: 1px solid #e2e8f0; margin-top: 0.25rem;">
                                            <?= e($cVal['text']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <!-- Case 2: Corrections Required by Librarian -->
                <?php elseif ($repoRecord && $repoRecord['status'] === 'corrections_required'): ?>
                    <div style="background: #fef3c7; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 1.25rem; margin-bottom: 1rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: #92400e; margin-bottom: 0.5rem;">
                            ⚠️ Repository Metadata Corrections Requested by Librarian
                        </h4>
                        <p style="font-size: 0.85rem; color: #78350f; margin-bottom: 0.75rem;">
                            <?= nl2br(e($repoRecord['repository_notes'] ?: 'Please review metadata details and resubmit.')); ?>
                        </p>

                        <?php if ($isProjectOwner && !isSuperAdmin()): ?>
                            <form action="../repository/submit.php" method="POST">
                                <?= csrfField(); ?>
                                <input type="hidden" name="project_id" value="<?= $projectId; ?>">

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label">Repository Title</label>
                                    <input type="text" name="title" class="form-control" value="<?= e($repoRecord['title']); ?>" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label">Abstract / Descriptive Summary</label>
                                    <textarea name="description" class="form-control" rows="3" required><?= e($repoRecord['description']); ?></textarea>
                                </div>
                                <button type="submit" class="btn-primary" style="font-weight: 700; background: #d97706;">
                                    🔄 Resubmit Corrected Metadata to Repository
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                <!-- Case 3: Submission Pending Review -->
                <?php elseif ($repoRecord && in_array($repoRecord['status'], ['submitted', 'approved'], true)): ?>
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-sm); padding: 1.25rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: #1e40af; margin-bottom: 0.5rem;">
                            ⏳ Repository Submission Pending Librarian Curation & Publishing
                        </h4>
                        <p style="font-size: 0.85rem; color: #1e3a8a; margin: 0;">
                            Submitted to repository on <?= date('F d, Y H:i', strtotime($repoRecord['submitted_to_repository_at'] ?? $repoRecord['created_at'])); ?>. The University Librarian is reviewing metadata and preparing persistent identifier assignment.
                        </p>
                    </div>

                <!-- Case 4: Eligible & Ready for Student Submission -->
                <?php elseif ($repoEligibility['is_eligible'] && $isProjectOwner && !isSuperAdmin()): ?>
                    <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.25rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem;">
                            📥 Submit Completed Research Project to Institutional Repository
                        </h4>
                        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                            Your project has satisfied all academic prerequisites, defense examinations, and institutional sign-offs. Confirm your metadata to initiate repository publication processing.
                        </p>

                        <form action="../repository/submit.php" method="POST">
                            <?= csrfField(); ?>
                            <input type="hidden" name="project_id" value="<?= $projectId; ?>">

                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label class="form-label" style="font-weight: 700;">Repository Project Title</label>
                                <input type="text" name="title" class="form-control" value="<?= e($project['title']); ?>" required>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.85rem;">
                                <label class="form-label" style="font-weight: 700;">Abstract / Summary</label>
                                <textarea name="description" class="form-control" rows="3" required><?= e($project['description']); ?></textarea>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; margin-bottom: 0.85rem;">
                                <div>
                                    <label class="form-label" style="font-weight: 700;">Subject Area / Keywords</label>
                                    <input type="text" name="keywords" class="form-control" placeholder="e.g. Computer Science, Machine Learning, Data Analytics">
                                </div>
                                <div>
                                    <label class="form-label" style="font-weight: 700;">Requested Visibility</label>
                                    <select name="access_level" class="form-control" required>
                                        <option value="public" selected>Public Access (Open Repository)</option>
                                        <option value="restricted">Restricted Access (Request Approval Required)</option>
                                        <option value="private">Private (Institutional Internal)</option>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" style="font-weight: 700; background: #059669; padding: 0.6rem 1.25rem;">
                                🚀 Submit to Repository Workflow
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- WORKFLOW STAGE 1: RESEARCH TOPIC -->
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin: 0;">
                        1. Research Topic Workflow
                    </h3>
                    <span style="font-size: 0.8rem; font-weight: 700; color: #475569;">
                        Status: <?= e($project['status']); ?>
                    </span>
                </div>

                <?php if ($topicRecord): ?>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1rem;">
                        <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Submitted Topic Title:</div>
                        <div style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin-top: 0.15rem;">
                            <?= e($topicRecord['topic_title']); ?>
                        </div>
                        <?php if ($topicRecord['abstract_summary']): ?>
                            <div style="font-size: 0.85rem; color: var(--text-main); margin-top: 0.5rem; line-height: 1.5;">
                                <strong>Abstract:</strong> <?= e($topicRecord['abstract_summary']); ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">
                            Submitted <?= date('M d, Y H:i', strtotime($topicRecord['created_at'])); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Topic Action Box for Student (Owner) -->
                <?php if ($isProjectOwner && !isSuperAdmin()): ?>
                    <?php if (in_array($project['status'], ['draft', 'planning'], true)): ?>
                        <!-- Initial Topic Submission Form -->
                        <form action="../supervision/topic_submit.php" method="POST" style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1.25rem; border-radius: var(--radius-sm);">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                            <h4 style="font-size: 0.95rem; color: #1e40af; margin-bottom: 0.75rem;">📤 Submit Research Topic for Supervisor Review</h4>
                            
                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Topic Title <span style="color:#dc2626;">*</span></label>
                                <input type="text" name="topic_title" class="form-control" value="<?= e($project['title']); ?>" required>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Research Area</label>
                                <input type="text" name="research_area" class="form-control" value="<?= e($project['research_area']); ?>">
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Abstract / Brief Summary</label>
                                <textarea name="abstract_summary" class="form-control" placeholder="Provide background summary and scope..."><?= e($project['description']); ?></textarea>
                            </div>

                            <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem; padding: 0.5rem 1.25rem;">
                                Submit Topic to Supervisor &rarr;
                            </button>
                        </form>
                    <?php elseif ($project['status'] === 'topic_submitted'): ?>
                        <div style="background: #e0f2fe; border: 1px solid #7dd3fc; padding: 0.85rem 1rem; border-radius: var(--radius-sm); color: #0369a1; font-size: 0.9rem;">
                            ⏳ <strong>Topic Under Review:</strong> Your research topic has been submitted and is currently awaiting supervisor review.
                        </div>
                    <?php elseif ($project['status'] === 'topic_corrections'): ?>
                        <!-- Topic Corrections Required Resubmission Form -->
                        <div style="background: #fffbebf5; border: 1px solid #fde68a; padding: 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1rem;">
                            <div style="color: #b45309; font-weight: 700; font-size: 0.95rem; margin-bottom: 0.4rem;">
                                ⚠️ Action Required: Topic Corrections Requested by Supervisor
                            </div>
                            <?php if ($topicRecord && $topicRecord['reviewer_feedback']): ?>
                                <div style="background: #ffffff; border: 1px solid #fcd34d; padding: 0.75rem; border-radius: var(--radius-sm); font-size: 0.875rem; color: #78350f; margin-bottom: 0.75rem; white-space: pre-wrap;">
                                    <strong>Supervisor Feedback:</strong> <?= e($topicRecord['reviewer_feedback']); ?>
                                </div>
                            <?php endif; ?>

                            <form action="../supervision/topic_submit.php" method="POST">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Revised Topic Title <span style="color:#dc2626;">*</span></label>
                                    <input type="text" name="topic_title" class="form-control" value="<?= e($topicRecord['topic_title'] ?? $project['title']); ?>" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Revised Abstract / Summary</label>
                                    <textarea name="abstract_summary" class="form-control"><?= e($topicRecord['abstract_summary'] ?? $project['description']); ?></textarea>
                                </div>
                                <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                    🔄 Resubmit Revised Topic &rarr;
                                </button>
                            </form>
                        </div>
                    <?php elseif (in_array($project['status'], ['topic_approved', 'proposal_submitted', 'proposal_corrections', 'proposal_approved', 'in_progress'], true)): ?>
                        <div style="background: #dcfce7; border: 1px solid #86efac; padding: 0.75rem 1rem; border-radius: var(--radius-sm); color: #15803d; font-size: 0.9rem;">
                            ✅ <strong>Topic Approved:</strong> Your research topic has been approved by your supervisor.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Topic Review Form for Supervisor -->
                <?php if (($isAssignedSupervisor || strtolower($systemRole) === 'admin') && !isSuperAdmin()): ?>
                    <?php if (in_array($project['status'], ['topic_submitted', 'topic_under_review'], true)): ?>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.25rem; border-radius: var(--radius-sm); margin-top: 1rem;">
                            <h4 style="font-size: 0.95rem; color: #166534; margin-bottom: 0.75rem;">⚖️ Supervisor Topic Review Form</h4>
                            <form action="../supervision/topic_review.php" method="POST">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Review Decision <span style="color:#dc2626;">*</span></label>
                                    <select name="decision" class="form-control" required style="font-weight: 600;">
                                        <option value="approved">✅ Approve Topic</option>
                                        <option value="corrections_required">⚠️ Request Corrections</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Feedback & Evaluative Comments</label>
                                    <textarea name="feedback_comments" class="form-control" placeholder="Provide guidance, suggestions, or correction requirements for the student..."></textarea>
                                </div>

                                <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                    Save Topic Evaluation &rarr;
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- WORKFLOW STAGE 2: RESEARCH PROPOSAL -->
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin: 0;">
                        2. Research Proposal Workflow
                    </h3>
                    <span style="font-size: 0.8rem; font-weight: 700; color: #475569;">
                        <?php if (empty($proposalSub)): ?>
                            Status: Awaiting Topic Approval
                        <?php else: ?>
                            Status: <?= e($proposalSub['status']); ?> (v<?= count($proposalVersions); ?>)
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Student Proposal Submission / Upload Box -->
                <?php if ($isProjectOwner && !isSuperAdmin()): ?>
                    <?php if (!in_array($project['status'], ['topic_approved', 'proposal_submitted', 'proposal_corrections', 'proposal_approved', 'in_progress'], true)): ?>
                        <div style="background: #f8fafc; border: 1px dashed var(--border-color); padding: 1rem; border-radius: var(--radius-sm); color: var(--text-muted); font-size: 0.875rem;">
                            🔒 Proposal submission will be unlocked automatically once your research topic is approved by your supervisor.
                        </div>
                    <?php elseif ($project['status'] === 'topic_approved' && empty($proposalSub)): ?>
                        <!-- Initial Proposal Upload Form -->
                        <form action="../supervision/proposal_submit.php" method="POST" enctype="multipart/form-data" style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1.25rem; border-radius: var(--radius-sm);">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                            <h4 style="font-size: 0.95rem; color: #1e40af; margin-bottom: 0.75rem;">📄 Submit Research Proposal Document (v1)</h4>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Proposal Document File (.pdf, .doc, .docx, .odt, .txt) <span style="color:#dc2626;">*</span></label>
                                <input type="file" name="proposal_file" class="form-control" required accept=".pdf,.doc,.docx,.odt,.txt">
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">Maximum file size: 25 MB</div>
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Proposal Title / Document Label</label>
                                <input type="text" name="proposal_title" class="form-control" value="Research Proposal - <?= e($project['title']); ?>">
                            </div>

                            <div class="form-group" style="margin-bottom: 0.75rem;">
                                <label class="form-label" style="font-size: 0.85rem;">Submission Notes / Message to Supervisor</label>
                                <textarea name="submission_notes" class="form-control" placeholder="Optional notes for your supervisor..."></textarea>
                            </div>

                            <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                Upload & Submit Proposal &rarr;
                            </button>
                        </form>
                    <?php elseif ($project['status'] === 'proposal_submitted'): ?>
                        <div style="background: #e0f2fe; border: 1px solid #7dd3fc; padding: 0.85rem 1rem; border-radius: var(--radius-sm); color: #0369a1; font-size: 0.9rem;">
                            ⏳ <strong>Proposal Under Review:</strong> Your research proposal document has been submitted and is currently awaiting supervisor review.
                        </div>
                    <?php elseif ($project['status'] === 'proposal_corrections'): ?>
                        <!-- Proposal Corrections Required Upload Form -->
                        <div style="background: #fffbebf5; border: 1px solid #fde68a; padding: 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1rem;">
                            <div style="color: #b45309; font-weight: 700; font-size: 0.95rem; margin-bottom: 0.4rem;">
                                ⚠️ Action Required: Proposal Corrections Requested by Supervisor
                            </div>
                            <?php if (!empty($proposalReviews)): ?>
                                <div style="background: #ffffff; border: 1px solid #fcd34d; padding: 0.75rem; border-radius: var(--radius-sm); font-size: 0.875rem; color: #78350f; margin-bottom: 0.75rem; white-space: pre-wrap;">
                                    <strong>Supervisor Feedback:</strong> <?= e($proposalReviews[0]['feedback_comments']); ?>
                                </div>
                            <?php endif; ?>

                            <form action="../supervision/proposal_submit.php" method="POST" enctype="multipart/form-data">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                
                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Upload Revised Proposal Document (.pdf, .doc, .docx, .odt) <span style="color:#dc2626;">*</span></label>
                                    <input type="file" name="proposal_file" class="form-control" required accept=".pdf,.doc,.docx,.odt,.txt">
                                </div>

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Revision Notes</label>
                                    <textarea name="submission_notes" class="form-control" placeholder="Summarize the changes made in response to supervisor feedback..."></textarea>
                                </div>

                                <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                    🔄 Upload Revised Proposal Version &rarr;
                                </button>
                            </form>
                        </div>
                    <?php elseif (in_array($project['status'], ['proposal_approved', 'in_progress'], true)): ?>
                        <div style="background: #dcfce7; border: 1px solid #86efac; padding: 0.75rem 1rem; border-radius: var(--radius-sm); color: #15803d; font-size: 0.9rem;">
                            🎉 <strong>Proposal Approved:</strong> Your research proposal has been fully approved by your academic supervisor!
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Supervisor Proposal Review Box -->
                <?php if (($isAssignedSupervisor || strtolower($systemRole) === 'admin') && !isSuperAdmin()): ?>
                    <?php if (in_array($project['status'], ['proposal_submitted', 'proposal_under_review'], true) && $proposalSub): ?>
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.25rem; border-radius: var(--radius-sm); margin-top: 1rem;">
                            <h4 style="font-size: 0.95rem; color: #166534; margin-bottom: 0.75rem;">⚖️ Supervisor Proposal Evaluation Form</h4>
                            <form action="../supervision/proposal_review.php" method="POST" enctype="multipart/form-data">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                <input type="hidden" name="submission_id" value="<?= (int)$proposalSub['id']; ?>">

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Evaluation Decision <span style="color:#dc2626;">*</span></label>
                                    <select name="decision" class="form-control" required style="font-weight: 600;">
                                        <option value="approved">✅ Approve Proposal</option>
                                        <option value="corrections_required">⚠️ Request Proposal Corrections</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Detailed Feedback Comments</label>
                                    <textarea name="feedback_comments" class="form-control" placeholder="Provide section-by-section review or corrections required..."></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 0.75rem;">
                                    <label class="form-label" style="font-size: 0.85rem;">Optional Annotated Review Attachment (.pdf, .docx, .doc, .zip)</label>
                                    <input type="file" name="attachment_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.zip">
                                </div>

                                <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                    Save Proposal Evaluation &rarr;
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Proposal Versioning History Table -->
                <?php if (!empty($proposalVersions)): ?>
                    <div style="margin-top: 1.5rem;">
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem; text-transform: uppercase;">
                            📁 Proposal Version History (<?= count($proposalVersions); ?>)
                        </h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>File Name</th>
                                    <th>Size</th>
                                    <th>Uploaded By</th>
                                    <th>Uploaded Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($proposalVersions as $pv): ?>
                                    <tr>
                                        <td>
                                            <span style="font-family: monospace; font-weight: 700; background: #eff6ff; color: #1e40af; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                v<?= (int)$pv['version_number']; ?>
                                            </span>
                                        </td>
                                        <td><strong><?= e($pv['file_name']); ?></strong></td>
                                        <td><?= formatFileSize((int)$pv['file_size']); ?></td>
                                        <td><?= e($pv['uploader_first'] . ' ' . $pv['uploader_last']); ?></td>
                                        <td><?= date('M d, Y H:i', strtotime($pv['created_at'])); ?></td>
                                        <td>
                                            <a href="../supervision/proposal_download.php?version_id=<?= (int)$pv['id']; ?>" class="btn-header" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                                📥 Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Supervisor Review History Log -->
                <?php if (!empty($proposalReviews)): ?>
                    <div style="margin-top: 1.5rem;">
                        <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.5rem; text-transform: uppercase;">
                            📜 Evaluation History & Feedback Logs
                        </h4>
                        <?php foreach ($proposalReviews as $pr): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 0.5rem; font-size: 0.85rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                                    <div>
                                        <strong>Prof./Dr. <?= e($pr['rev_first'] . ' ' . $pr['rev_last']); ?></strong> &bull; 
                                        <span style="color: var(--text-muted);"><?= date('M d, Y H:i', strtotime($pr['reviewed_at'])); ?></span>
                                    </div>
                                    <div>
                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 9999px; text-transform: uppercase; background: <?= $pr['decision'] === 'approved' ? '#dcfce7; color: #15803d;' : '#fef3c7; color: #b45309;'; ?>">
                                            <?= e($pr['decision']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="color: var(--text-main); white-space: pre-wrap; margin-top: 0.25rem;">
                                    <?= e($pr['feedback_comments']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- WORKFLOW STAGE 3: CHAPTER & RESEARCH DOCUMENT SUBMISSIONS (PHASE 5) -->
        <div style="margin-top: 1.5rem; margin-bottom: 2rem;">
            <!-- Chapter Structure Format Configuration Card -->
            <?php 
            $currentChapterCount = (int)($project['chapter_count'] ?? 5);
            if ($canEdit || $isProjectOwner || $isAssignedSupervisor || strtolower($systemRole) === 'admin'):
            ?>
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.1rem 1.4rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: var(--shadow-sm); border-left: 4px solid var(--accent-color);">
                    <div>
                        <div style="font-size: 0.95rem; font-weight: 700; color: var(--primary-color); display: flex; align-items: center; gap: 0.4rem;">
                            <span>📚 Research Structure Format:</span>
                            <span style="color: var(--accent-color); font-weight: 800; font-size: 0.95rem;">
                                <?= $currentChapterCount === 7 ? '7 Chapters (Extended / System Design / PhD)' : '5 Chapters (Standard Undergraduate / MSc)'; ?>
                            </span>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.25rem;">
                            Configures the total chapter milestones and submission sequence required for this research project.
                        </div>
                    </div>

                    <?php if (!isSuperAdmin()): ?>
                        <form action="view.php?id=<?= (int)$project['id']; ?>" method="POST" style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap;">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="update_chapter_structure">
                            
                            <select name="chapter_count" class="form-control" style="font-size: 0.85rem; font-weight: 700; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: #f8fafc; color: var(--primary-color);">
                                <option value="5" <?= $currentChapterCount === 5 ? 'selected' : ''; ?>>5 Chapters (Standard)</option>
                                <option value="7" <?= $currentChapterCount === 7 ? 'selected' : ''; ?>>7 Chapters (Extended)</option>
                            </select>

                            <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem; padding: 0.4rem 0.9rem;">
                                ⚙️ Switch Structure &rarr;
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--primary-color); margin-bottom: 1rem; border-bottom: 2px solid var(--primary-light); padding-bottom: 0.4rem;">
                3. Chapters & Research Document Submissions (<?= $currentChapterCount; ?> Chapters Format)
            </h3>

            <?php 
            $chaptersData = $supervisionData['chapters'];
            foreach ($chaptersData as $typeKey => $cItem):
                $cDef = $cItem['definition'];
                $cSub = $cItem['submission'];
                $cVer = $cItem['versions'];
                $cRev = $cItem['reviews'];
                $isUnlocked = $cItem['unlocked'];
                $cStatus = $cSub ? $cSub['status'] : ($isUnlocked ? 'ready' : 'locked');
            ?>
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.25rem; border-left: 4px solid <?= $cStatus === 'approved' ? '#059669' : ($cStatus === 'corrections_required' ? '#d97706' : ($isUnlocked ? '#2563eb' : '#94a3b8')); ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                        <h4 style="font-size: 1rem; font-weight: 700; color: var(--primary-color); margin: 0;">
                            <?= e($cDef['label']); ?>
                        </h4>
                        <div>
                            <?php if ($cStatus === 'approved'): ?>
                                <span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">✅ Approved</span>
                            <?php elseif ($cStatus === 'corrections_required'): ?>
                                <span style="background: #fef3c7; color: #b45309; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">⚠️ Corrections Required</span>
                            <?php elseif ($cStatus === 'submitted' || $cStatus === 'under_review'): ?>
                                <span style="background: #dbeafe; color: #1e40af; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">⏳ Submitted & Under Review</span>
                            <?php elseif ($isUnlocked): ?>
                                <span style="background: #eff6ff; color: #2563eb; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">🔓 Unlocked — Ready for Submission</span>
                            <?php else: ?>
                                <span style="background: #f1f5f9; color: #64748b; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 9999px; text-transform: uppercase;">🔒 Locked (Awaiting <?= e($cDef['prev_label']); ?> Approval)</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Student Upload / Action Form -->
                    <?php if ($isProjectOwner && !isSuperAdmin()): ?>
                        <?php if (!$isUnlocked && empty($cSub)): ?>
                            <div style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">
                                🔒 Submission for <?= e($cDef['short_name']); ?> will be unlocked automatically once <?= e($cDef['prev_label']); ?> is approved by your supervisor.
                            </div>
                        <?php elseif ($isUnlocked && (empty($cSub) || $cStatus === 'corrections_required')): ?>
                            <div style="background: <?= $cStatus === 'corrections_required' ? '#fffbebf5' : '#eff6ff'; ?>; border: 1px solid <?= $cStatus === 'corrections_required' ? '#fde68a' : '#bfdbfe'; ?>; padding: 1.1rem; border-radius: var(--radius-sm); margin-bottom: 0.75rem;">
                                <?php if ($cStatus === 'corrections_required'): ?>
                                    <div style="color: #b45309; font-weight: 700; font-size: 0.9rem; margin-bottom: 0.4rem;">
                                        ⚠️ Action Required: <?= e($cDef['short_name']); ?> Corrections Requested by Supervisor
                                    </div>
                                    <?php if (!empty($cRev)): ?>
                                        <div style="background: #ffffff; border: 1px solid #fcd34d; padding: 0.65rem; border-radius: var(--radius-sm); font-size: 0.85rem; color: #78350f; margin-bottom: 0.75rem; white-space: pre-wrap;">
                                            <strong>Supervisor Feedback:</strong> <?= e($cRev[0]['feedback_comments']); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <form action="../supervision/chapter_submit.php" method="POST" enctype="multipart/form-data">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                    <input type="hidden" name="stage_type" value="<?= e($typeKey); ?>">

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Document File (.pdf, .doc, .docx, .odt, .txt) <span style="color:#dc2626;">*</span></label>
                                        <input type="file" name="document_file" class="form-control" required accept=".pdf,.doc,.docx,.odt,.txt">
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Document Title / Version Label</label>
                                        <input type="text" name="document_title" class="form-control" value="<?= e($cDef['short_name']); ?> - <?= e($project['title']); ?>">
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Submission Notes / Comments for Supervisor</label>
                                        <textarea name="submission_notes" class="form-control" placeholder="Optional comments regarding this chapter submission..."></textarea>
                                    </div>

                                    <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                        <?= $cStatus === 'corrections_required' ? '🔄 Resubmit Revised ' . e($cDef['short_name']) : '📤 Upload & Submit ' . e($cDef['short_name']); ?> &rarr;
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Supervisor Review Form for Chapter -->
                    <?php if (($isAssignedSupervisor || strtolower($systemRole) === 'admin') && !isSuperAdmin()): ?>
                        <?php if ($cSub && in_array($cSub['status'], ['submitted', 'under_review'], true)): ?>
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.1rem; border-radius: var(--radius-sm); margin-bottom: 0.75rem;">
                                <h5 style="font-size: 0.9rem; color: #166534; margin-bottom: 0.6rem; font-weight: 700;">⚖️ Supervisor Evaluation for <?= e($cDef['short_name']); ?></h5>
                                <form action="../supervision/chapter_review.php" method="POST" enctype="multipart/form-data">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                    <input type="hidden" name="submission_id" value="<?= (int)$cSub['id']; ?>">

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Evaluation Decision <span style="color:#dc2626;">*</span></label>
                                        <select name="decision" class="form-control" required style="font-weight: 600;">
                                            <option value="approved">✅ Approve <?= e($cDef['short_name']); ?></option>
                                            <option value="corrections_required">⚠️ Request Corrections</option>
                                        </select>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Evaluative Feedback Comments</label>
                                        <textarea name="feedback_comments" class="form-control" placeholder="Detailed feedback or corrections required..."></textarea>
                                    </div>

                                    <div class="form-group" style="margin-bottom: 0.6rem;">
                                        <label class="form-label" style="font-size: 0.85rem;">Optional Annotated File Attachment (.pdf, .docx, .doc, .zip)</label>
                                        <input type="file" name="attachment_file" class="form-control" accept=".pdf,.doc,.docx,.txt,.zip">
                                    </div>

                                    <button type="submit" class="btn-header btn-header-primary" style="font-size: 0.85rem;">
                                        Save <?= e($cDef['short_name']); ?> Evaluation &rarr;
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Chapter Version History Table -->
                    <?php if (!empty($cVer)): ?>
                        <div style="margin-top: 1rem;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: var(--primary-color); text-transform: uppercase; margin-bottom: 0.4rem;">
                                📁 <?= e($cDef['short_name']); ?> Version History (<?= count($cVer); ?>)
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Document Name</th>
                                        <th>Size</th>
                                        <th>Uploaded By</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cVer as $cv): ?>
                                        <tr>
                                            <td>
                                                <span style="font-family: monospace; font-weight: 700; background: #eff6ff; color: #1e40af; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                    v<?= (int)$cv['version_number']; ?>
                                                </span>
                                            </td>
                                            <td><strong><?= e($cv['file_name']); ?></strong></td>
                                            <td><?= formatFileSize((int)$cv['file_size']); ?></td>
                                            <td><?= e($cv['uploader_first'] . ' ' . $cv['uploader_last']); ?></td>
                                            <td><?= date('M d, Y H:i', strtotime($cv['created_at'])); ?></td>
                                            <td>
                                                <a href="../supervision/chapter_download.php?version_id=<?= (int)$cv['id']; ?>" class="btn-header" style="font-size: 0.75rem; padding: 0.25rem 0.55rem;">
                                                    📥 Download
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Chapter Supervisor Feedback History -->
                    <?php if (!empty($cRev)): ?>
                        <div style="margin-top: 1rem;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: var(--primary-color); text-transform: uppercase; margin-bottom: 0.4rem;">
                                📜 <?= e($cDef['short_name']); ?> Evaluation Logs
                            </div>
                            <?php foreach ($cRev as $cr): ?>
                                <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem 0.85rem; margin-bottom: 0.4rem; font-size: 0.85rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                        <div>
                                            <strong>Prof./Dr. <?= e($cr['rev_first'] . ' ' . $cr['rev_last']); ?></strong> &bull; 
                                            <span style="color: var(--text-muted);"><?= date('M d, Y H:i', strtotime($cr['reviewed_at'])); ?></span>
                                        </div>
                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 0.15rem 0.45rem; border-radius: 9999px; text-transform: uppercase; background: <?= $cr['decision'] === 'approved' ? '#dcfce7; color: #15803d;' : '#fef3c7; color: #b45309;'; ?>">
                                            <?= e($cr['decision']); ?>
                                        </span>
                                    </div>
                                    <div style="color: var(--text-main); white-space: pre-wrap;">
                                        <?= e($cr['feedback_comments']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </div>

        <!-- Section: Data Management Plan (DMP Integration - Step 6) -->
        <div class="section-card">
            <h2 class="section-heading">📋 Data Management Plan (DMP)</h2>

            <?php if (!$projectDmp): ?>
                <div class="dmp-banner">
                    <div>
                        <h3 style="font-size: 1.05rem; color: var(--primary-color); margin-bottom: 0.35rem;">
                            No Data Management Plan Created Yet
                        </h3>
                        <p style="color: var(--text-muted); font-size: 0.9rem;">
                            Define research data collection protocols, security measures, open access sharing and long-term digital preservation plans.
                        </p>
                    </div>
                    <div>
                        <?php if ($canMembers): ?>
                            <a href="../dmp/create.php?project_id=<?= (int)$project['id']; ?>" class="btn-header btn-header-primary">
                                ➕ Author Data Management Plan
                            </a>
                        <?php else: ?>
                            <span style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">
                                Awaiting project manager to author DMP
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="dmp-banner">
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                            <strong style="font-size: 1.05rem; color: var(--text-main);">DMP #<?= (int)$projectDmp['id']; ?></strong>
                            <?= getDmpStatusBadge($projectDmp['status']); ?>
                        </div>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">
                            <strong>Expected Volume:</strong> <?= e($projectDmp['expected_volume'] ?: 'Not defined'); ?> &bull; 
                            <strong>Storage Location:</strong> <?= e($projectDmp['storage_location'] ?: 'Not defined'); ?> &bull; 
                            <strong>Last Updated:</strong> <?= date('M d, Y H:i', strtotime($projectDmp['updated_at'])); ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="../dmp/view.php?id=<?= (int)$projectDmp['id']; ?>" class="btn-header">
                            👁️ View Full DMP
                        </a>
                        <?php if ($canEditDmpFlag): ?>
                            <a href="../dmp/edit.php?id=<?= (int)$projectDmp['id']; ?>" class="btn-header btn-header-primary">
                                ✏️ Edit DMP
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Deposited Datasets (Step 7) -->
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-heading" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    📊 Deposited Datasets (<?= count($projectDatasets); ?>)
                </h2>
                <?php if ($canMembers): ?>
                    <a href="../datasets/create.php?project_id=<?= (int)$project['id']; ?>" class="btn-header btn-header-primary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                        ➕ Deposit New Dataset
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($projectDatasets)): ?>
                <div style="background: #f8fafc; border: 1px dashed var(--border-color); border-radius: var(--radius-sm); padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                    No research datasets have been deposited under this project yet.
                    <?php if ($canMembers): ?>
                        <div style="margin-top: 0.75rem;">
                            <a href="../datasets/create.php?project_id=<?= (int)$project['id']; ?>" style="color: var(--accent-color); font-weight: 600; text-decoration: none;">
                                Deposit first dataset &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dataset Title</th>
                            <th>Type</th>
                            <th>Access</th>
                            <th>Version</th>
                            <th>Size</th>
                            <th>Deposited By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projectDatasets as $pds): 
                            $canDl = canDownloadDataset($projectRole, $systemRole, $pds['access_level'], (int)$pds['owner_id'], $userId);
                        ?>
                            <tr>
                                <td>
                                    <a href="../datasets/view.php?id=<?= (int)$pds['id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                        <?= e($pds['title']); ?>
                                    </a>
                                </td>
                                <td><?= e($pds['dataset_type'] ?: 'Data'); ?></td>
                                <td>
                                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 4px; background: #f1f5f9; color: #475569;">
                                        <?= e($pds['access_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-weight: 700; background: #eff6ff; color: #1e40af; padding: 0.2rem 0.45rem; border-radius: 4px; font-size: 0.8rem;">
                                        v<?= (int)$pds['current_version']; ?>
                                    </span>
                                </td>
                                <td><?= formatFileSize((int)$pds['total_size']); ?></td>
                                <td><?= e($pds['owner_first_name'] . ' ' . $pds['owner_last_name']); ?></td>
                                <td>
                                    <a href="../datasets/view.php?id=<?= (int)$pds['id']; ?>" style="color: var(--accent-color); font-weight: 600; text-decoration: none; font-size: 0.85rem; margin-right: 0.5rem;">
                                        View
                                    </a>
                                    <?php if ($canDl): ?>
                                        <a href="../datasets/download.php?id=<?= (int)$pds['id']; ?>" style="color: #059669; font-weight: 600; text-decoration: none; font-size: 0.85rem;">
                                            Download
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Section: Overview & Objectives -->
        <div class="section-card">
            <h2 class="section-heading">📌 Project Overview & Objectives</h2>

            <?php if (!empty($project['description'])): ?>
                <div class="info-item">
                    <div class="info-label">Project Description</div>
                    <div class="text-block"><?= e($project['description']); ?></div>
                </div>
            <?php endif; ?>

            <div class="info-item" style="margin-top: 1rem;">
                <div class="info-label">Research Objectives</div>
                <div class="text-block"><?= e($project['objectives']); ?></div>
            </div>

            <?php if (!empty($project['methodology'])): ?>
                <div class="info-item" style="margin-top: 1rem;">
                    <div class="info-label">Research Methodology</div>
                    <div class="text-block"><?= e($project['methodology']); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section: Data Characteristics & Governance -->
        <div class="section-card">
            <h2 class="section-heading">🏷️ Data Characteristics & Governance</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Research Area / Domain</div>
                    <div class="info-value"><strong><?= e($project['research_area']); ?></strong></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Primary Data Type</div>
                    <div class="info-value"><?= e($project['data_type']); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Data Collection Location</div>
                    <div class="info-value"><?= e($project['data_collection_location']); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Funding Information / Grant ID</div>
                    <div class="info-value"><?= e($project['funding_information'] ?: 'Not Specified / Internal'); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Ethical Approval Information</div>
                    <div class="info-value"><?= e($project['ethical_approval_information'] ?: 'Exempt / Not Required'); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Timeline / Schedule</div>
                    <div class="info-value">
                        <?= e($project['start_date']); ?> &rarr; <?= e($project['completion_date'] ?: 'Ongoing'); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section: Project Team -->
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-heading" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    👥 Research Team Members (<?= count($teamMembers); ?>)
                </h2>
                <?php if ($canMembers): ?>
                    <a href="members.php?id=<?= (int)$project['id']; ?>" class="btn-header" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                        ➕ Add / Manage Members
                    </a>
                <?php endif; ?>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Researcher</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Project Role</th>
                        <th>Date Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teamMembers as $m): ?>
                        <tr>
                            <td>
                                <strong><?= e($m['first_name'] . ' ' . $m['last_name']); ?></strong>
                                <?php if ((int)$m['user_id'] === (int)$project['owner_id']): ?>
                                    <span style="font-size: 0.7rem; color: #dc2626; font-weight: 700; margin-left: 4px;">[Owner]</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($m['email']); ?></td>
                            <td><?= e($m['department'] ?: $m['institution']); ?></td>
                            <td><?= getMemberRoleBadge($m['project_role']); ?></td>
                            <td><?= date('M d, Y', strtotime($m['joined_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
