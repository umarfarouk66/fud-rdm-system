<?php
/**
 * Supervisor Topic Review Controller
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
$decision = trim($_POST['decision'] ?? ''); // 'approved' or 'corrections_required'
$feedbackComments = trim($_POST['feedback_comments'] ?? '');

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid project identifier.';
    header('Location: ../supervisor/dashboard.php');
    exit;
}

if (!in_array($decision, ['approved', 'corrections_required'], true)) {
    $_SESSION['project_error'] = 'Invalid review decision.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}

if ($decision === 'corrections_required' && empty($feedbackComments)) {
    $_SESSION['project_error'] = 'Feedback comments are mandatory when requesting topic corrections.';
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

    // 2. Supervisor scoping check (Admin bypass allowed if needed, supervisor enforced)
    if ($systemRole === 'supervisor' && !isSupervisorOfStudent($pdo, $userId, $studentId)) {
        $_SESSION['project_error'] = 'Access denied: You are not the active assigned supervisor for this student.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 3. State transition check
    $allowedStatuses = ['topic_submitted', 'topic_under_review'];
    if (!in_array($project['status'], $allowedStatuses, true)) {
        $_SESSION['project_error'] = "Cannot review topic: Project is currently in '{$project['status']}' state.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 4. Fetch existing topic
    $tStmt = $pdo->prepare("SELECT id FROM project_topics WHERE project_id = :project_id LIMIT 1");
    $tStmt->execute([':project_id' => $projectId]);
    $topic = $tStmt->fetch(PDO::FETCH_ASSOC);

    if (!$topic) {
        $_SESSION['project_error'] = 'No topic submission record found for this project.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $pdo->beginTransaction();

    $newTopicStatus   = ($decision === 'approved') ? 'approved' : 'corrections_required';
    $newProjectStatus = ($decision === 'approved') ? 'topic_approved' : 'topic_corrections';

    // Update project_topics
    $upTopicStmt = $pdo->prepare("
        UPDATE project_topics
        SET status = :status,
            reviewed_by = :reviewer,
            reviewer_feedback = :feedback,
            reviewed_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upTopicStmt->execute([
        ':status'   => $newTopicStatus,
        ':reviewer' => $userId,
        ':feedback' => $feedbackComments,
        ':id'       => $topic['id']
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
        $notifTitle = "Topic Approved!";
        $notifMsg   = "Your supervisor {$supervisorName} has approved your research topic '{$project['title']}'. You may now proceed to Proposal submission.";
    } else {
        $notifTitle = "Topic Corrections Required";
        $notifMsg   = "Your supervisor {$supervisorName} requested corrections on your research topic. Please check the feedback comments and resubmit.";
    }

    createNotification($pdo, $studentId, $notifTitle, $notifMsg, 'topic_review', "../projects/view.php?id={$projectId}");

    // 6. Audit log
    $auditAction = ($decision === 'approved') ? 'topic_approved' : 'topic_corrections_requested';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Supervisor {$supervisorName} marked topic as '{$decision}' with feedback.");

    $pdo->commit();

    $_SESSION['project_success'] = ($decision === 'approved')
        ? "Topic approved successfully! The student can now proceed to submit their proposal."
        : "Topic review saved. Corrections requested from the student.";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Topic Review Controller Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while processing the topic review.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
