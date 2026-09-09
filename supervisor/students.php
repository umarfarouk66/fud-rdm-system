<?php
/**
 * Assigned Students Directory for Supervisor Workspace
 * FUD RDM & Project Supervision System - Step 16 (Phase 3)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';

// Enforce supervisor role
requireRole('supervisor');

$user   = currentUser();
$userId = (int)$user['id'];

// If specific student_id parameter passed, check IDOR protection
$targetStudentId = (int)($_GET['student_id'] ?? 0);
if ($targetStudentId > 0) {
    // IDOR Check: Ensure student is assigned to this supervisor
    $checkStmt = $pdo->prepare("
        SELECT id FROM student_supervisors 
        WHERE supervisor_id = :vid AND student_id = :sid AND status = 'active' 
        LIMIT 1
    ");
    $checkStmt->execute([':vid' => $userId, ':sid' => $targetStudentId]);
    if (!$checkStmt->fetch()) {
        http_response_code(403);
        $_SESSION['access_error'] = 'Access Denied: You are not authorized to access private records for this student.';
        header('Location: students.php');
        exit;
    }
}

// Fetch all active assigned students for current supervisor
$stmt = $pdo->prepare("
    SELECT 
        ss.id AS assignment_id, ss.academic_session, ss.supervision_type, ss.assigned_at,
        u.id AS student_id, u.first_name, u.last_name, u.email, u.phone, u.matric_number, u.department, u.faculty, u.academic_level,
        p.name AS programme_name,
        rp.id AS project_id, rp.project_code, rp.title AS project_title, rp.status AS project_status
    FROM student_supervisors ss
    INNER JOIN users u ON ss.student_id = u.id
    LEFT JOIN programmes p ON u.programme_id = p.id
    LEFT JOIN research_projects rp ON u.id = rp.owner_id AND rp.status != 'archived'
    WHERE ss.supervisor_id = :supervisor_id AND ss.status = 'active'
    ORDER BY ss.assigned_at DESC
");
$stmt->execute([':supervisor_id' => $userId]);
$assignedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Students — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .student-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
        }
        .student-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        .student-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .matric-badge {
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #334155;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            padding: 0.35rem 0;
            color: var(--text-main);
        }
        .project-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.85rem;
            margin-top: 1rem;
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
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Supervisor Dashboard
            </a>
        </div>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                👨‍🎓 My Assigned Student Roster
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Directory of student researchers officially assigned to your supervision roster for academic project guidance.
            </p>
        </div>

        <!-- Students Grid -->
        <?php if (empty($assignedStudents)): ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 3rem; text-align: center; color: var(--text-muted);">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">👨‍🎓</div>
                <h3 style="font-size: 1.15rem; color: var(--primary-color); margin-bottom: 0.25rem;">No Students Currently Assigned</h3>
                <p style="font-size: 0.9rem;">You have no active student supervision assignments. System administrators assign student researchers to your roster.</p>
            </div>
        <?php else: ?>
            <div class="students-grid">
                <?php foreach ($assignedStudents as $st): ?>
                    <div class="student-card">
                        <div class="student-card-header">
                            <div>
                                <div class="student-name"><?= e($st['first_name'] . ' ' . $st['last_name']); ?></div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($st['email']); ?></div>
                            </div>
                            <span class="matric-badge"><?= e($st['matric_number'] ?: 'Matric Unset'); ?></span>
                        </div>

                        <div class="info-row">
                            <span style="color: var(--text-muted);">Department:</span>
                            <strong><?= e($st['department'] ?: 'Unspecified'); ?></strong>
                        </div>
                        <div class="info-row">
                            <span style="color: var(--text-muted);">Programme & Level:</span>
                            <strong><?= e($st['programme_name'] ?: 'Undergraduate'); ?> (<?= e($st['academic_level'] ?: 'Final Year'); ?>)</strong>
                        </div>
                        <div class="info-row">
                            <span style="color: var(--text-muted);">Supervision Role:</span>
                            <span style="font-size: 0.75rem; font-weight: 700; padding: 0.1rem 0.45rem; background: #e0e7ff; color: #3730a3; border-radius: 9999px;">
                                <?= e(ucfirst($st['supervision_type'])); ?> (<?= e($st['academic_session']); ?>)
                            </span>
                        </div>
                        <div class="info-row">
                            <span style="color: var(--text-muted);">Assigned Date:</span>
                            <span><?= date('M d, Y', strtotime($st['assigned_at'])); ?></span>
                        </div>

                        <!-- Associated Project -->
                        <div class="project-box">
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.25rem;">Research Project</div>
                            <?php if (!empty($st['project_title'])): ?>
                                <div style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color); margin-bottom: 0.25rem;">
                                    <?= e($st['project_title']); ?>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 0.5rem;">
                                    <span style="font-family: monospace; font-size: 0.75rem; color: var(--accent-color); font-weight: 700;"><?= e($st['project_code']); ?></span>
                                    <a href="student_profile.php?student_id=<?= (int)$st['student_id']; ?>" style="font-size: 0.8rem; font-weight: 700; color: #ffffff; background: var(--primary-color); padding: 0.35rem 0.75rem; border-radius: 4px; text-decoration: none;">
                                        Supervision Profile &rarr;
                                    </a>
                                </div>
                            <?php else: ?>
                                <div style="font-size: 0.85rem; color: var(--text-muted); font-style: italic; margin-bottom: 0.5rem;">
                                    Student has not registered a project topic yet.
                                </div>
                                <a href="student_profile.php?student_id=<?= (int)$st['student_id']; ?>" style="font-size: 0.8rem; font-weight: 700; color: var(--accent-color); text-decoration: none; display: inline-block;">
                                    Open Supervision Profile &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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
