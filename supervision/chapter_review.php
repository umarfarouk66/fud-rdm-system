<?php
/**
 * Supervisor Chapter & Document Review Controller
 * RDM Information System - Phase 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/supervision_helpers.php';

requireRole(['supervisor', 'admin']);
enforceWritePermission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../supervisor/dashboard.php');
    exit;
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token). Please try again.';
    header('Location: ../supervisor/dashboard.php');
    exit;
}

$user = currentUser();
$userId = (int)$user['id'];
$systemRole = $user['role'];
$projectId = (int)($_POST['project_id'] ?? 0);
$submissionId = (int)($_POST['submission_id'] ?? 0);
$decision = trim($_POST['decision'] ?? ''); // 'approved' or 'corrections_required'
$feedbackComments = trim($_POST['feedback_comments'] ?? '');

$chCount = getProjectChapterCount($pdo, $projectId);
$stages = getChapterStageDefinitions($chCount);

if ($projectId <= 0 || $submissionId <= 0) {
    $_SESSION['project_error'] = 'Invalid submission review parameters.';
    header('Location: ../supervisor/dashboard.php');
    exit;
}

if (!in_array($decision, ['approved', 'corrections_required'], true)) {
    $_SESSION['project_error'] = 'Invalid evaluation decision choice.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}

if ($decision === 'corrections_required' && empty($feedbackComments)) {
    $_SESSION['project_error'] = 'Feedback comments are mandatory when requesting document corrections.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}

try {
    // 1. Fetch project details
    $pStmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'Research project not found.';
        header('Location: ../supervisor/dashboard.php');
        exit;
    }

    $studentId = (int)$project['owner_id'];

    // 2. Supervisor scoping check
    if ($systemRole === 'supervisor' && !isSupervisorOfStudent($pdo, $userId, $studentId)) {
        $_SESSION['project_error'] = 'Access denied: You are not the active assigned supervisor for this student.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 3. Fetch submission
    $subStmt = $pdo->prepare("SELECT * FROM project_submissions WHERE id = :id AND project_id = :p_id LIMIT 1");
    $subStmt->execute([':id' => $submissionId, ':p_id' => $projectId]);
    $submission = $subStmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        $_SESSION['project_error'] = 'Submission record not found.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $stageType = $submission['submission_type'];
    $stageDef  = $stages[$stageType] ?? ['short_name' => str_replace('_', ' ', $stageType)];

    // Handle optional supervisor attachment file upload
    $attachmentPath = null;
    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
        $attFile = $_FILES['attachment_file'];
        $attOrigName = basename($attFile['name']);
        $attExt = strtolower(pathinfo($attOrigName, PATHINFO_EXTENSION));
        $allowedAttExts = ['pdf', 'doc', 'docx', 'txt', 'zip'];

        if (in_array($attExt, $allowedAttExts, true) && $attFile['size'] <= 15 * 1024 * 1024) {
            $attStorageDir = __DIR__ . '/../storage/reviews/';
            if (!is_dir($attStorageDir)) {
                mkdir($attStorageDir, 0755, true);
            }
            $attStoredName = "review_att_{$submissionId}_" . bin2hex(random_bytes(6)) . ".{$attExt}";
            if (move_uploaded_file($attFile['tmp_name'], $attStorageDir . $attStoredName)) {
                $attachmentPath = 'storage/reviews/' . $attStoredName;
            }
        }
    }

    $pdo->beginTransaction();

    $newSubStatus     = ($decision === 'approved') ? 'approved' : 'corrections_required';
    $newProjectStatus = ($decision === 'approved') ? "{$stageType}_approved" : "{$stageType}_corrections";

    // 4. Insert supervisor_reviews record
    $insRevSql = "
        INSERT INTO supervisor_reviews (
            submission_id, reviewer_id, decision, feedback_comments, attachment_file_path, reviewed_at
        ) VALUES (
            :sub_id, :reviewer_id, :decision, :feedback, :att_path, CURRENT_TIMESTAMP
        )
    ";
    $insRevStmt = $pdo->prepare($insRevSql);
    $insRevStmt->execute([
        ':sub_id'      => $submissionId,
        ':reviewer_id' => $userId,
        ':decision'    => $newSubStatus,
        ':feedback'    => $feedbackComments,
        ':att_path'    => $attachmentPath
    ]);

    // Update project_submissions status
    $upSubStmt = $pdo->prepare("
        UPDATE project_submissions
        SET status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upSubStmt->execute([
        ':status' => $newSubStatus,
        ':id'     => $submissionId
    ]);

    // Update research_projects status
    $upProjStmt = $pdo->prepare("
        UPDATE research_projects
        SET status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upProjStmt->execute([
        ':status' => $newProjectStatus,
        ':id'     => $projectId
    ]);

    // 5. Notify student
    $supervisorName = 'Prof./Dr. ' . $user['first_name'] . ' ' . $user['last_name'];
    if ($decision === 'approved') {
        $notifTitle = "{$stageDef['short_name']} Approved!";
        $notifMsg   = "Your supervisor {$supervisorName} has approved your {$stageDef['short_name']} submission for project '{$project['title']}'.";
    } else {
        $notifTitle = "{$stageDef['short_name']} Corrections Required";
        $notifMsg   = "Your supervisor {$supervisorName} requested corrections on {$stageDef['short_name']}. Please review feedback and resubmit your revised version.";
    }

    createNotification($pdo, $studentId, $notifTitle, $notifMsg, 'chapter_review', "../projects/view.php?id={$projectId}");

    // 6. Audit log
    $auditAction = ($decision === 'approved') ? 'chapter_approved' : 'chapter_corrections_requested';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Supervisor {$supervisorName} evaluated {$stageDef['short_name']} as '{$decision}'.");

    $pdo->commit();

    $_SESSION['project_success'] = ($decision === 'approved')
        ? "{$stageDef['short_name']} approved successfully!"
        : "Evaluation saved. Corrections requested from student on {$stageDef['short_name']}.";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Chapter Review Controller Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while processing document review.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
