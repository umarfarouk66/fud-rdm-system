<?php
/**
 * Researcher Dashboard
 * RDM Information System - Step 4, 5, 6, 7, 8 & 9
 * Institutional Discovery & Project Management Portal
 * Phase 6: Advanced Supervision, Milestones & Progress Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';

// Enforce researcher role
requireRole('researcher');

$user = currentUser();
$userId = (int)$user['id'];
$systemRole = $user['role'];
$accessError = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_error']);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);

// Dynamic database metrics
$projectCount = 0;
$datasetCount = 0;
$datasetDraft = 0;
$datasetPublished = 0;
$datasetDownloads = 0;
$datasetTotalSize = 0;

$dmpCount     = 0;
$dmpDraft     = 0;
$dmpSubmitted = 0;
$dmpApproved  = 0;

$myRequestsTotal    = 0;
$myRequestsPending  = 0;
$myRequestsApproved = 0;
$myRequestsRejected = 0;
$incomingPendingReq = 0;

$myProjects = [];
$repoProjects = [];
$repoTotalProjects = 0;
$repoTotalPages = 1;
$repoPage = max(1, (int)($_GET['repo_page'] ?? 1));
$repoLimit = 6;
$repoOffset = ($repoPage - 1) * $repoLimit;

$mySupervisionProject = null;
$supervisionData = null;

try {
    // 1. Projects owned or collaborated on by current researcher
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) 
        FROM research_projects p 
        LEFT JOIN project_members pm ON p.id = pm.project_id 
        WHERE p.owner_id = :user_id OR pm.user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $projectCount = (int)$stmt->fetchColumn();

    // Fetch My Projects list
    $myStmt = $pdo->prepare("
        SELECT 
            p.*, 
            pm.role AS member_role,
            COUNT(d.id) AS dataset_count
        FROM research_projects p
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
        LEFT JOIN datasets d ON p.id = d.project_id AND d.status != 'deleted'
        WHERE p.owner_id = :user_id OR pm.user_id = :user_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT 6
    ");
    $myStmt->execute([':user_id' => $userId]);
    $myProjects = $myStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get researcher's primary owned project for supervision tracking
    $primaryProjStmt = $pdo->prepare("
        SELECT id FROM research_projects WHERE owner_id = :uid AND status != 'archived' ORDER BY created_at DESC LIMIT 1
    ");
    $primaryProjStmt->execute([':uid' => $userId]);
    $primProjId = $primaryProjStmt->fetchColumn();
    if ($primProjId) {
        ensureDefaultProjectMilestones($pdo, (int)$primProjId);
        syncProjectMilestoneStatuses($pdo, (int)$primProjId);
        $supervisionData = getProjectSupervisionData($pdo, (int)$primProjId);
    }

    // 2. Institutional Repository Projects
    $repoCountStmt = $pdo->query("SELECT COUNT(DISTINCT id) FROM research_projects");
    $repoTotalProjects = (int)$repoCountStmt->fetchColumn();
    $repoTotalPages = max(1, (int)ceil($repoTotalProjects / $repoLimit));

    $repoStmt = $pdo->prepare("
        SELECT 
            p.*, 
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.department AS owner_department,
            u.institution AS owner_institution,
            pm.role AS current_user_member_role,
            COUNT(d.id) AS dataset_count
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
        LEFT JOIN datasets d ON p.id = d.project_id AND d.status != 'deleted'
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $repoStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $repoStmt->bindValue(':limit', $repoLimit, PDO::PARAM_INT);
    $repoStmt->bindValue(':offset', $repoOffset, PDO::PARAM_INT);
    $repoStmt->execute();
    $repoProjects = $repoStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Datasets Breakdown
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT d.id) AS total_datasets,
            COUNT(DISTINCT CASE WHEN d.status = 'draft' THEN d.id END) AS draft_datasets,
            COUNT(DISTINCT CASE WHEN d.status = 'published' THEN d.id END) AS published_datasets,
            COALESCE(SUM(d.download_count), 0) AS total_downloads,
            COALESCE(SUM(d.total_size), 0) AS total_size_bytes
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        LEFT JOIN project_members pm ON p.id = pm.project_id
        WHERE d.owner_id = :user_id OR p.owner_id = :user_id OR pm.user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $datasetStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $datasetCount     = (int)($datasetStats['total_datasets'] ?? 0);
    $datasetDraft     = (int)($datasetStats['draft_datasets'] ?? 0);
    $datasetPublished = (int)($datasetStats['published_datasets'] ?? 0);
    $datasetDownloads = (int)($datasetStats['total_downloads'] ?? 0);
    $datasetTotalSize = (int)($datasetStats['total_size_bytes'] ?? 0);

    // 4. DMPs Breakdown
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT d.id) AS total_dmp,
            COUNT(DISTINCT CASE WHEN d.status = 'draft' THEN d.id END) AS draft_dmp,
            COUNT(DISTINCT CASE WHEN d.status = 'submitted' THEN d.id END) AS submitted_dmp,
            COUNT(DISTINCT CASE WHEN d.status = 'approved' THEN d.id END) AS approved_dmp
        FROM data_management_plans d
        INNER JOIN research_projects p ON d.project_id = p.id
        LEFT JOIN project_members pm ON p.id = pm.project_id
        WHERE p.owner_id = :user_id OR pm.user_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $dmpStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $dmpCount     = (int)($dmpStats['total_dmp'] ?? 0);
    $dmpDraft     = (int)($dmpStats['draft_dmp'] ?? 0);
    $dmpSubmitted = (int)($dmpStats['submitted_dmp'] ?? 0);
    $dmpApproved  = (int)($dmpStats['approved_dmp'] ?? 0);

    // 5. Access Requests Breakdown
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_req,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_req,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved_req,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected_req
        FROM access_requests
        WHERE requester_id = :user_id
    ");
    $stmt->execute([':user_id' => $userId]);
    $reqStats = $stmt->fetch(PDO::FETCH_ASSOC);

    $myRequestsTotal    = (int)($reqStats['total_req'] ?? 0);
    $myRequestsPending  = (int)($reqStats['pending_req'] ?? 0);
    $myRequestsApproved = (int)($reqStats['approved_req'] ?? 0);
    $myRequestsRejected = (int)($reqStats['rejected_req'] ?? 0);

    // 6. Active Supervisor
    $supStmt = $pdo->prepare("
        SELECT 
            ss.status AS assignment_status,
            ss.assigned_at,
            ss.academic_session AS session_name,
            u.first_name AS sup_first_name,
            u.last_name AS sup_last_name,
            u.email AS sup_email,
            u.phone AS sup_phone,
            sp.staff_id AS sup_staff_id,
            d.name AS sup_department,
            f.name AS sup_faculty
        FROM student_supervisors ss
        INNER JOIN users u ON ss.supervisor_id = u.id
        LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
        LEFT JOIN departments d ON sp.department_id = d.id
        LEFT JOIN faculties f ON d.faculty_id = f.id
        WHERE ss.student_id = :student_id AND ss.status = 'active'
        ORDER BY ss.assigned_at DESC
        LIMIT 1
    ");
    $supStmt->execute([':student_id' => $userId]);
    $mySupervisor = $supStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Researcher Dashboard Query Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Researcher Dashboard — FUD RDM System</title>
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
            height: 10px;
            overflow: hidden;
            width: 100%;
            margin: 0.4rem 0;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
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
                    <div class="user-affiliation"><?= e($user['department'] ?: ($user['institution'] ?: 'Researcher')); ?></div>
                </div>
                <span class="role-badge role-badge-researcher">Student / Researcher</span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Dashboard Container -->
    <main class="dash-container">

        <!-- Welcome Banner -->
        <div class="dash-welcome">
            <div class="welcome-text">
                <h1>Welcome, <?= e($user['first_name']); ?>!</h1>
                <p>Manage your academic research project lifecycle, track supervisor evaluation feedback, deposit datasets and monitor milestone deadlines.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <?php if ($supervisionData): ?>
                    <a href="../projects/view.php?id=<?= (int)$supervisionData['project']['id']; ?>" style="display: inline-flex; align-items: center; background: #ffffff; color: var(--primary-color); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                        🎓 My Active Research Project
                    </a>
                <?php else: ?>
                    <a href="../projects/create.php" style="display: inline-flex; align-items: center; background: #ffffff; color: var(--primary-color); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                        ➕ Register Research Project
                    </a>
                <?php endif; ?>
                <a href="../datasets/create.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none;">
                    📊 Deposit Dataset
                </a>
                <a href="../reports/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.25); color: #ffffff; border: 1px solid rgba(255,255,255,0.5); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none;">
                    📈 My Progress & Reports
                </a>
            </div>
        </div>

        <!-- ACADEMIC SUPERVISION & PROGRESS CENTER -->
        <?php if ($supervisionData): ?>
            <div class="content-card" style="margin-bottom: 2rem; border-left: 4px solid var(--accent-color);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                    <div>
                        <span style="font-family: monospace; font-size: 0.8rem; font-weight: 700; color: var(--accent-color);"><?= e($supervisionData['project']['project_code']); ?></span>
                        <h2 style="font-size: 1.35rem; color: var(--primary-color); font-weight: 700; margin-top: 0.2rem;">
                            <?= e($supervisionData['project']['title']); ?>
                        </h2>
                    </div>
                    <a href="../projects/view.php?id=<?= (int)$supervisionData['project']['id']; ?>" class="btn-primary" style="font-size: 0.85rem; padding: 0.5rem 1rem; text-decoration: none; font-weight: 700;">
                        📂 Open Project File & Submit Work &rarr;
                    </a>
                </div>

                <!-- Progress Bar -->
                <div style="margin-bottom: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 0.9rem;">
                        <span>Current Stage: <strong style="color: var(--primary-color);"><?= e($supervisionData['current_stage_label']); ?></strong></span>
                        <span style="color: #059669;"><?= (int)$supervisionData['progress_percentage']; ?>% Completed</span>
                    </div>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill" style="width: <?= (int)$supervisionData['progress_percentage']; ?>%;"></div>
                    </div>
                </div>

                <!-- Outstanding Corrections Required Alert -->
                <?php 
                $openCorrections = [];
                foreach ($supervisionData['corrections'] as $c) {
                    if ($c['status'] !== 'accepted') $openCorrections[] = $c;
                }
                ?>
                <?php if (!empty($openCorrections)): ?>
                    <div style="background: #fffbebf5; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 1.1rem; margin-bottom: 1.25rem;">
                        <h3 style="font-size: 0.95rem; color: #b45309; font-weight: 700; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                            🛠 Action Required: Supervisor Requested Corrections (<?= count($openCorrections); ?>)
                        </h3>
                        <table class="recent-table">
                            <thead>
                                <tr>
                                    <th>Correction Title</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($openCorrections as $cor): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--primary-color);"><?= e($cor['correction_title']); ?></td>
                                        <td style="font-size: 0.8rem; max-width: 250px;"><?= nl2br(e($cor['correction_details'])); ?></td>
                                        <td><span class="badge-status badge-<?= e($cor['status']); ?>"><?= e(ucfirst($cor['status'])); ?></span></td>
                                        <td>
                                            <?php if ($cor['status'] === 'open'): ?>
                                                <!-- Student Address Correction Form -->
                                                <form action="../supervision/correction_manage.php" method="POST" style="display: flex; gap: 0.35rem; align-items: center;">
                                                    <?= csrfField(); ?>
                                                    <input type="hidden" name="correction_id" value="<?= (int)$cor['id']; ?>">
                                                    <input type="hidden" name="action" value="address">
                                                    <input type="text" name="student_response" placeholder="Brief fix note..." required style="padding: 0.25rem; font-size: 0.75rem; border: 1px solid var(--border-color); border-radius: 4px; width: 140px;">
                                                    <button type="submit" class="btn-sm" style="background: var(--primary-color); color: #fff; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                                        Mark Addressed
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: #1e40af; font-weight: 600;">⏳ Awaiting Supervisor Verification</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <!-- Phase 8: Final Readiness & Defense (Viva Voce) Widget -->
                <?php 
                $myReadiness = checkFinalProjectReadiness($pdo, (int)$supervisionData['project']['id']);
                $myDefense   = getProjectDefense($pdo, (int)$supervisionData['project']['id']);
                $myOutcome   = $myDefense ? getDefenseOutcome($pdo, (int)$myDefense['id']) : null;
                ?>
                <div style="margin-top: 1.5rem; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1.25rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                        <h3 style="font-size: 1rem; color: var(--primary-color); font-weight: 700; margin: 0;">
                            🎓 Final Readiness & Oral Defense (Viva Voce) Status
                        </h3>
                        <?php if ($myReadiness['is_ready']): ?>
                            <span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 9999px; font-weight: 700;">
                                ✅ <?= $myReadiness['ready_count']; ?> / <?= $myReadiness['total_count']; ?> Requirements Complete
                            </span>
                        <?php else: ?>
                            <span style="background: #fef3c7; color: #92400e; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 9999px; font-weight: 700;">
                                ⏳ <?= $myReadiness['ready_count']; ?> / <?= $myReadiness['total_count']; ?> Checklist Items Complete
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($myDefense): ?>
                        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 0.75rem;">
                            <div style="font-weight: 700; color: #1e40af; font-size: 0.9rem; margin-bottom: 0.25rem;">
                                📅 Scheduled Oral Defense: <?= date('F d, Y', strtotime($myDefense['defense_date'])); ?> @ <?= e($myDefense['start_time']); ?>
                            </div>
                            <div style="font-size: 0.8rem; color: #1e3a8a;">
                                📍 Venue: <?= e($myDefense['venue']); ?> <?= $myDefense['room_location'] ? '(' . e($myDefense['room_location']) . ')' : ''; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($myOutcome): ?>
                        <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; color: #065f46;">
                            <strong>Examination Result:</strong> <span style="font-weight: 800; text-transform: uppercase;"><?= str_replace('_', ' ', $myOutcome['outcome']); ?></span>
                        </div>
                    <?php endif; ?>

                    <a href="../projects/view.php?id=<?= (int)$supervisionData['project']['id']; ?>" style="display: inline-block; margin-top: 0.75rem; font-size: 0.8rem; color: var(--accent-color); font-weight: 700; text-decoration: none;">
                        View Full Defense Registry & Readiness Checklist &rarr;
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Academic Supervisor Information Widget -->
        <div class="content-card" style="margin-bottom: 2rem; border-left: 4px solid <?= $mySupervisor ? '#059669' : '#d97706'; ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                <div>
                    <h2 class="section-title" style="margin-bottom: 0.25rem;">👨‍🏫 My Academic Supervisor</h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Institutional academic supervisor details</p>
                </div>
                <div>
                    <?php if ($mySupervisor): ?>
                        <span style="background: #d1fae5; color: #065f46; font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 9999px; text-transform: uppercase;">
                            Active Supervision
                        </span>
                    <?php else: ?>
                        <span style="background: #fef3c7; color: #92400e; font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 9999px; text-transform: uppercase;">
                            Supervisor: Pending Assignment
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($mySupervisor): ?>
                <div style="margin-top: 1rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; background: #f8fafc; padding: 1.1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Supervisor Name</div>
                        <div style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color); margin-top: 0.2rem;">
                            Prof./Dr. <?= e($mySupervisor['sup_first_name'] . ' ' . $mySupervisor['sup_last_name']); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Department</div>
                        <div style="font-size: 0.95rem; font-weight: 600; color: var(--text-main); margin-top: 0.2rem;">
                            <?= e($mySupervisor['sup_department'] ?: 'Faculty Supervisor'); ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Contact & Communication</div>
                        <div style="font-size: 0.9rem; font-weight: 600; color: var(--accent-color); margin-top: 0.2rem;">
                            <a href="mailto:<?= e($mySupervisor['sup_email']); ?>" style="color: var(--accent-color); text-decoration: none;">
                                ✉️ <?= e($mySupervisor['sup_email']); ?>
                            </a>
                        </div>
                        <div style="margin-top: 0.5rem;">
                            <?php if ($supervisionData && isset($supervisionData['project'])): ?>
                                <a href="../messages/index.php?project_id=<?= (int)$supervisionData['project']['id']; ?>" class="btn-primary" style="font-size: 0.8rem; padding: 0.35rem 0.85rem; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    💬 Message Supervisor &rarr;
                                </a>
                            <?php else: ?>
                                <a href="../messages/index.php" class="btn-primary" style="font-size: 0.8rem; padding: 0.35rem 0.85rem; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    💬 Open Supervision Messages &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div style="margin-top: 1rem; background: #fffbebf5; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 1rem 1.25rem; color: #b45309; font-size: 0.9rem;">
                    <strong>No Supervisor Assigned Yet:</strong> The Postgraduate / Departmental Coordinator will assign your supervisor shortly.
                </div>
            <?php endif; ?>
        </div>

        <!-- My Projects List -->
        <div class="content-card" style="margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-title" style="margin-bottom: 0;">My Registered Projects</h2>
                <a href="../projects/create.php" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.4rem 0.85rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600;">
                    ➕ Register New Project
                </a>
            </div>
            <?php if (empty($myProjects)): ?>
                <p style="color: var(--text-muted); font-size: 0.9rem;">No projects registered yet.</p>
            <?php else: ?>
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myProjects as $mp): ?>
                            <tr>
                                <td style="font-family: monospace; font-weight: 700; color: var(--accent-color);"><?= e($mp['project_code']); ?></td>
                                <td><strong><?= e($mp['title']); ?></strong></td>
                                <td><span style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700;"><?= e($mp['status']); ?></span></td>
                                <td><a href="../projects/view.php?id=<?= (int)$mp['id']; ?>" style="color: var(--accent-color); font-weight: 600; text-decoration: none;">View Workspace &rarr;</a></td>
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
