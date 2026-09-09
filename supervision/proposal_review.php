<?php
/**
 * Supervisor Proposal Review Controller
 * RDM Information System - Phase 4
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

if ($projectId <= 0 || $submissionId <= 0) {
    $_SESSION['project_error'] = 'Invalid submission parameters.';
    header('Location: ../supervisor/dashboard.php');
    exit;
}

if (!in_array($decision, ['approved', 'corrections_required'], true)) {
    $_SESSION['project_error'] = 'Invalid review decision choice.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}

if ($decision === 'corrections_required' && empty($feedbackComments)) {
    $_SESSION['project_error'] = 'Feedback comments are mandatory when requesting proposal corrections.';
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

    // 3. State transition check
    $allowedStatuses = ['proposal_submitted', 'proposal_under_review'];
    if (!in_array($project['status'], $allowedStatuses, true)) {
        $_SESSION['project_error'] = "Cannot review proposal: Project is currently in '{$project['status']}' state.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 4. Fetch submission
    $subStmt = $pdo->prepare("SELECT * FROM project_submissions WHERE id = :id AND project_id = :p_id LIMIT 1");
    $subStmt->execute([':id' => $submissionId, ':p_id' => $projectId]);
    $submission = $subStmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        $_SESSION['project_error'] = 'Submission record not found.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

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
    $newProjectStatus = ($decision === 'approved') ? 'proposal_approved' : 'proposal_corrections';

    // 5. Insert supervisor_reviews record
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

    // 6. Notify student
    $supervisorName = 'Prof./Dr. ' . $user['first_name'] . ' ' . $user['last_name'];
    if ($decision === 'approved') {
        $notifTitle = "Research Proposal Approved!";
        $notifMsg   = "Congratulations! Your supervisor {$supervisorName} has approved your research proposal for project '{$project['title']}'.";
    } else {
        $notifTitle = "Proposal Corrections Required";
        $notifMsg   = "Your supervisor {$supervisorName} requested corrections on your research proposal. Please review the feedback and upload your revised proposal.";
    }

    createNotification($pdo, $studentId, $notifTitle, $notifMsg, 'proposal_review', "../projects/view.php?id={$projectId}");

    // 7. Audit log
    $auditAction = ($decision === 'approved') ? 'proposal_approved' : 'proposal_corrections_requested';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Supervisor {$supervisorName} reviewed proposal and decided '{$decision}'.");

    $pdo->commit();

    $_SESSION['project_success'] = ($decision === 'approved')
        ? "Proposal approved successfully! Project status updated to Proposal Approved."
        : "Proposal review submitted. Corrections requested from the student.";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Proposal Review Controller Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while processing the proposal review.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
