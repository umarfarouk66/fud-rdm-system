<?php
/**
 * Supervisor Final Review & Defense Approval Controller
 * FUD RDM System - Phase 8: Final Project Approval + Defense/Viva Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/defense_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../supervisor/dashboard.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireRole(['supervisor', 'admin']);
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];
$role   = strtolower($user['role'] ?? '');

$projectId = (int)($_POST['project_id'] ?? 0);
$decision  = trim($_POST['decision'] ?? ''); // 'approved' or 'corrections_required'
$remarks   = trim($_POST['final_remarks'] ?? '');

if ($projectId <= 0 || !in_array($decision, ['approved', 'corrections_required'], true)) {
    $_SESSION['flash_error'] = 'Invalid final review decision parameters.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../supervisor/dashboard.php'));
    exit;
}

// Fetch project details
$pStmt = $pdo->prepare("SELECT id, owner_id, title, status FROM research_projects WHERE id = :id LIMIT 1");
$pStmt->execute([':id' => $projectId]);
$project = $pStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    $_SESSION['flash_error'] = 'Research project not found.';
    header('Location: ../supervisor/dashboard.php');
    exit;
}

$studentId = (int)$project['owner_id'];

// If Supervisor, check IDOR / active student assignment
if ($role === 'supervisor') {
    $checkStmt = $pdo->prepare("
        SELECT id FROM student_supervisors 
        WHERE supervisor_id = :sup_id AND student_id = :sid AND status = 'active'
        LIMIT 1
    ");
    $checkStmt->execute([':sup_id' => $userId, ':sid' => $studentId]);
    if (!$checkStmt->fetch()) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'Access Denied: You are not assigned to supervise this student.';
        header('Location: ../supervisor/students.php');
        exit;
    }
}

if ($decision === 'approved') {
    // Check readiness checklist
    $readiness = checkFinalProjectReadiness($pdo, $projectId);
    if (!$readiness['is_ready']) {
        $_SESSION['flash_error'] = 'Cannot approve for defense: Project has unfulfilled prerequisite requirements or unaccepted corrections.';
        header("Location: ../supervisor/student_profile.php?student_id={$studentId}");
        exit;
    }

    $newStatus = 'final_submission_approved';
    $upd = $pdo->prepare("UPDATE research_projects SET status = :status, updated_at = NOW() WHERE id = :id");
    $upd->execute([':status' => $newStatus, ':id' => $projectId]);

    // Recalculate progress & sync milestones
    syncProjectMilestoneStatuses($pdo, $projectId);

    // Audit log
    log_audit($pdo, 'supervisor_final_approval_granted', 'research_project', $projectId, "Supervisor User #{$userId} approved project '{$project['title']}' for defense. Remarks: {$remarks}");

    // Notifications
    createNotification(
        $pdo, 
        $studentId, 
        'final_approval_granted', 
        '🎓 Approved for Defense!', 
        "Your supervisor approved your research project '{$project['title']}' for final oral defense.", 
        "../projects/view.php?id={$projectId}"
    );

    $_SESSION['flash_success'] = "Project '{$project['title']}' has been approved for defense!";
} else {
    // Request final corrections
    $newStatus = 'final_submission_corrections';
    $upd = $pdo->prepare("UPDATE research_projects SET status = :status, updated_at = NOW() WHERE id = :id");
    $upd->execute([':status' => $newStatus, ':id' => $projectId]);

    if (!empty($remarks)) {
        $insCorr = $pdo->prepare("
            INSERT INTO project_corrections (
                project_id, version_number, correction_title, correction_details, 
                requested_by, status, created_at, updated_at
            ) VALUES (
                :pid, 1, 'Final Draft Supervisor Corrections', :details, :uid, 'open', NOW(), NOW()
            )
        ");
        $insCorr->execute([
            ':pid'     => $projectId,
            ':details' => $remarks,
            ':uid'     => $userId
        ]);
    }

    // Audit log
    log_audit($pdo, 'supervisor_final_corrections_requested', 'research_project', $projectId, "Supervisor User #{$userId} requested final draft corrections for project '{$project['title']}'.");

    // Notifications
    createNotification(
        $pdo, 
        $studentId, 
        'final_corrections_requested', 
        '⚠️ Final Draft Corrections Requested', 
        "Your supervisor requested corrections on your final project draft before defense scheduling.", 
        "../projects/view.php?id={$projectId}"
    );

    $_SESSION['flash_success'] = "Final draft corrections requested from student.";
}

header("Location: ../supervisor/student_profile.php?student_id={$studentId}");
exit;
