<?php
/**
 * Correction Tracking Management Processor
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

$user = currentUser();
$role = strtolower($user['role'] ?? '');

verifyCSRFToken();

$correctionId    = (int)($_POST['correction_id'] ?? 0);
$action          = trim($_POST['action'] ?? '');
$studentResponse = trim($_POST['student_response'] ?? '');

if ($correctionId <= 0 || !in_array($action, ['address', 'accept'])) {
    $_SESSION['flash_error'] = 'Invalid correction action parameters.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

// Fetch correction item
$corStmt = $pdo->prepare("
    SELECT c.*, rp.id AS project_id, rp.owner_id AS student_id, rp.title AS project_title
    FROM project_corrections c
    INNER JOIN research_projects rp ON c.project_id = rp.id
    WHERE c.id = :id
    LIMIT 1
");
$corStmt->execute([':id' => $correctionId]);
$correction = $corStmt->fetch(PDO::FETCH_ASSOC);

if (!$correction) {
    $_SESSION['flash_error'] = 'Correction record not found.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
    exit;
}

$studentId = (int)$correction['student_id'];
$projectId = (int)$correction['project_id'];

if ($action === 'address') {
    // Student marking correction as addressed
    if ($role !== 'researcher' && (int)$user['id'] !== $studentId) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'Access Denied: Only the project owner can submit correction responses.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
        exit;
    }

    $updStmt = $pdo->prepare("
        UPDATE project_corrections 
        SET status = 'addressed',
            student_response = :resp,
            addressed_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $updStmt->execute([
        ':resp' => !empty($studentResponse) ? $studentResponse : 'Addressed in updated draft.',
        ':id'   => $correctionId
    ]);

    // Audit logging
    log_audit(
        $pdo,
        'correction_addressed',
        'project_correction',
        $correctionId,
        "Addressed correction '{$correction['correction_title']}'",
        (int)$user['id']
    );

    // Notify Supervisor
    $supStmt = $pdo->prepare("
        SELECT supervisor_id FROM student_supervisors 
        WHERE student_id = :sid AND status = 'active'
    ");
    $supStmt->execute([':sid' => $studentId]);
    $supervisors = $supStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($supervisors as $supId) {
        createNotification(
            $pdo, 
            (int)$supId, 
            'correction_addressed', 
            '🛠 Correction Addressed by Student', 
            "Student has addressed correction item '{$correction['correction_title']}' on project '{$correction['project_title']}'.", 
            "../supervisor/student_profile.php?student_id={$studentId}"
        );
    }

    $_SESSION['flash_success'] = "Correction item '{$correction['correction_title']}' marked as addressed. Your supervisor has been notified.";
} 
elseif ($action === 'accept') {
    // Supervisor or Admin accepting correction
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
            $_SESSION['flash_error'] = 'Access Denied: You are not authorized to manage corrections for this student.';
            header('Location: ../supervisor/students.php');
            exit;
        }
    } elseif ($role !== 'admin') {
        http_response_code(403);
        die('Access Denied');
    }

    $updStmt = $pdo->prepare("
        UPDATE project_corrections 
        SET status = 'accepted',
            accepted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $updStmt->execute([':id' => $correctionId]);

    // Audit logging
    log_audit(
        $pdo,
        'correction_accepted',
        'project_correction',
        $correctionId,
        "Accepted correction '{$correction['correction_title']}'",
        (int)$user['id']
    );

    // Notify Student
    createNotification(
        $pdo, 
        $studentId, 
        'correction_accepted', 
        '✓ Correction Item Accepted', 
        "Your supervisor accepted correction item '{$correction['correction_title']}'.", 
        "../projects/view.php?id={$projectId}"
    );

    $_SESSION['flash_success'] = "Correction item '{$correction['correction_title']}' accepted and verified.";
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? "../supervisor/student_profile.php?student_id={$studentId}"));
exit;
