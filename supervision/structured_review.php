<?php
/**
 * Structured Review Processor with Correction Item Creation
 * FUD RDM System - Phase 6: Advanced Supervision, Milestones & Progress Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
enforceWritePermission();
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

$user = currentUser();
$role = strtolower($user['role'] ?? '');

if (!in_array($role, ['admin', 'supervisor'])) {
    http_response_code(403);
    die('Access Denied: Only supervisors and administrators can submit reviews.');
}

verifyCSRFToken();

$projectId    = (int)($_POST['project_id'] ?? 0);
$submissionId = (int)($_POST['submission_id'] ?? 0);
$decision     = trim($_POST['decision'] ?? '');
$generalFb    = trim($_POST['general_feedback'] ?? '');
$reqCorr      = trim($_POST['required_corrections'] ?? '');
$recommend    = trim($_POST['recommendations'] ?? '');

if ($projectId <= 0 || !in_array($decision, ['approved', 'corrections_required'])) {
    $_SESSION['project_error'] = 'Invalid review decision inputs.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? "../projects/view.php?id={$projectId}"));
    exit;
}

// Fetch project
$projStmt = $pdo->prepare("SELECT id, owner_id, title, status FROM research_projects WHERE id = :id LIMIT 1");
$projStmt->execute([':id' => $projectId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    $_SESSION['project_error'] = 'Research project not found.';
    header('Location: ../projects/index.php');
    exit;
}

$studentId = (int)$project['owner_id'];

// If Supervisor, verify IDOR / student assignment
if ($role === 'supervisor') {
    $supervisorId = (int)$user['id'];
    $checkStmt = $pdo->prepare("
        SELECT id FROM student_supervisors 
        WHERE supervisor_id = :sup_id AND student_id = :sid AND status = 'active'
        LIMIT 1
    ");
    $checkStmt->execute([':sup_id' => $supervisorId, ':sid' => $studentId]);
    if (!$checkStmt->fetch()) {
        http_response_code(403);
        $_SESSION['project_error'] = 'Access Denied: You are not assigned to supervise this student.';
        header('Location: ../supervisor/students.php');
        exit;
    }
}

// Combine all feedback into a master summary for supervisor_reviews table
$combinedFeedback = trim("General Feedback:\n" . ($generalFb ?: 'N/A') . "\n\nRequired Corrections:\n" . ($reqCorr ?: 'None') . "\n\nRecommendations:\n" . ($recommend ?: 'None'));

// Get current submission version number if applicable
$versionNumber = 1;
if ($submissionId > 0) {
    $subStmt = $pdo->prepare("SELECT current_version FROM project_submissions WHERE id = :id LIMIT 1");
    $subStmt->execute([':id' => $submissionId]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    if ($sub) {
        $versionNumber = (int)$sub['current_version'];
    }
}

// Insert into supervisor_reviews table
$revStmt = $pdo->prepare("
    INSERT INTO supervisor_reviews (
        submission_id, project_id, reviewer_id, decision, feedback_comments, 
        general_feedback, required_corrections, recommendations, reviewed_at
    ) VALUES (
        :submission_id, :project_id, :reviewer_id, :decision, :feedback_comments,
        :general_feedback, :required_corrections, :recommendations, NOW()
    )
");
$revStmt->execute([
    ':submission_id'        => $submissionId > 0 ? $submissionId : null,
    ':project_id'           => $projectId,
    ':reviewer_id'          => (int)$user['id'],
    ':decision'             => $decision,
    ':feedback_comments'    => $combinedFeedback,
    ':general_feedback'     => $generalFb,
    ':required_corrections' => $reqCorr,
    ':recommendations'      => $recommend
]);
$reviewId = (int)$pdo->lastInsertId();

// If corrections required, automatically log item in project_corrections table
if ($decision === 'corrections_required' && !empty($reqCorr)) {
    $corrStmt = $pdo->prepare("
        INSERT INTO project_corrections (
            project_id, submission_id, version_number, correction_title, 
            correction_details, requested_by, status, created_at, updated_at
        ) VALUES (
            :project_id, :submission_id, :version_number, :correction_title,
            :correction_details, :requested_by, 'open', NOW(), NOW()
        )
    ");
    $corrStmt->execute([
        ':project_id'         => $projectId,
        ':submission_id'      => $submissionId > 0 ? $submissionId : null,
        ':version_number'     => $versionNumber,
        ':correction_title'   => 'Supervisor Review Corrections (v' . $versionNumber . ')',
        ':correction_details' => $reqCorr,
        ':requested_by'       => (int)$user['id']
    ]);
}

// Update submission status if applicable
if ($submissionId > 0) {
    $newSubStatus = ($decision === 'approved') ? 'approved' : 'corrections_required';
    $updSub = $pdo->prepare("UPDATE project_submissions SET status = :status, updated_at = NOW() WHERE id = :id");
    $updSub->execute([':status' => $newSubStatus, ':id' => $submissionId]);
}

// Recalculate progress & sync milestones
syncProjectMilestoneStatuses($pdo, $projectId);

// Audit log
log_audit(
    $pdo,
    'supervisor_review_submitted',
    'supervisor_review',
    $reviewId,
    "Submitted review decision '" . strtoupper($decision) . "' for project ID {$projectId}",
    (int)$user['id']
);

// Notify Student
$notifMsg = ($decision === 'approved')
    ? "Your supervisor approved your submission for project '{$project['title']}'."
    : "Your supervisor requested corrections on your submission for project '{$project['title']}'.";

createNotification(
    $pdo, 
    $studentId, 
    'review_completed', 
    ($decision === 'approved' ? '✅ Review Approved' : '⚠️ Corrections Requested'), 
    $notifMsg, 
    "../projects/view.php?id={$projectId}"
);

$_SESSION['project_success'] = "Review evaluation submitted successfully. Decision: " . strtoupper($decision);
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? "../projects/view.php?id={$projectId}"));
exit;
