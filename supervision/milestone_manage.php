<?php
/**
 * Milestone Management Processor
 * FUD RDM System - Phase 6: Advanced Supervision, Milestones & Progress Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
enforceWritePermission();
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';

// Only POST requests allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

// Ensure logged in and is admin or supervisor
$user = currentUser();
$role = strtolower($user['role'] ?? '');

if (!in_array($role, ['admin', 'supervisor'])) {
    http_response_code(403);
    die('Access Denied: Students are not permitted to modify milestone state.');
}

verifyCSRFToken();

$milestoneId = (int)($_POST['milestone_id'] ?? 0);
$projectId   = (int)($_POST['project_id'] ?? 0);
$dueDate     = trim($_POST['due_date'] ?? '');
$status      = trim($_POST['status'] ?? '');
$notes       = trim($_POST['milestone_notes'] ?? '');

if ($milestoneId <= 0 || $projectId <= 0) {
    $_SESSION['flash_error'] = 'Invalid milestone or project identifier.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

// Fetch project and owner details
$projStmt = $pdo->prepare("SELECT id, owner_id, title FROM research_projects WHERE id = :id LIMIT 1");
$projStmt->execute([':id' => $projectId]);
$project = $projStmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    $_SESSION['flash_error'] = 'Research project not found.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

$studentId = (int)$project['owner_id'];

// If Supervisor, check IDOR / student assignment
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
        $_SESSION['flash_error'] = 'Access Denied: You are not assigned to supervise this student.';
        header('Location: ../supervisor/students.php');
        exit;
    }
}

// Validate status
$validStatuses = ['pending', 'in_progress', 'completed', 'overdue'];
if (!in_array($status, $validStatuses)) {
    $status = 'pending';
}

$dueDateVal = !empty($dueDate) ? $dueDate : null;
$completionDate = ($status === 'completed') ? date('Y-m-d H:i:s') : null;

// Fetch current milestone to check previous state
$mStmt = $pdo->prepare("SELECT * FROM project_milestones WHERE id = :id AND project_id = :pid LIMIT 1");
$mStmt->execute([':id' => $milestoneId, ':pid' => $projectId]);
$milestone = $mStmt->fetch(PDO::FETCH_ASSOC);

if (!$milestone) {
    $_SESSION['flash_error'] = 'Milestone record not found for this project.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

// Update milestone
$updStmt = $pdo->prepare("
    UPDATE project_milestones 
    SET due_date = :due_date,
        status = :status,
        completed_at = CASE WHEN :status = 'completed' AND completed_at IS NULL THEN NOW() WHEN :status != 'completed' THEN NULL ELSE completed_at END,
        description = COALESCE(:notes, description),
        updated_at = NOW()
    WHERE id = :id AND project_id = :pid
");
$updStmt->execute([
    ':due_date' => $dueDateVal,
    ':status'   => $status,
    ':notes'    => !empty($notes) ? $notes : null,
    ':id'       => $milestoneId,
    ':pid'      => $projectId
]);

// Recalculate progress and sync milestone statuses
syncProjectMilestoneStatuses($pdo, $projectId);

$mTitle = $milestone['title'] ?? $milestone['milestone_name'] ?? 'Milestone';

// Audit logging
log_audit(
    $pdo,
    'milestone_updated',
    'project_milestone',
    $milestoneId,
    "Updated milestone '{$mTitle}' to " . strtoupper($status) . " for project ID {$projectId}",
    (int)$user['id']
);

// Notify student of milestone updates/completion
if ($studentId > 0 && $status !== $milestone['status']) {
    $msg = "Milestone update for project '{$project['title']}': Milestone '{$mTitle}' is now marked as " . strtoupper(str_replace('_', ' ', $status)) . ".";
    createNotification($pdo, $studentId, 'milestone_update', '🎯 Project Milestone Updated', $msg, "../projects/view.php?id={$projectId}");
}

$_SESSION['flash_success'] = "Milestone '{$mTitle}' updated successfully.";
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? "../supervisor/student_profile.php?student_id={$studentId}"));
exit;
