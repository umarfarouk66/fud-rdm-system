<?php
/**
 * Submit Data Management Plan Controller
 * RDM Information System - Step 6
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dmp_helpers.php';

// Enforce authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$dmpId = (int)($_POST['dmp_id'] ?? 0);

if ($dmpId <= 0) {
    $_SESSION['dmp_error'] = 'Invalid Data Management Plan identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['dmp_error'] = 'Security validation failed (invalid CSRF token). Submission aborted.';
    header("Location: view.php?id={$dmpId}");
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.project_code,
            p.title AS project_title,
            p.owner_id AS project_owner_id
        FROM data_management_plans d
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $dmpId]);
    $dmp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dmp) {
        $_SESSION['dmp_error'] = 'The requested Data Management Plan was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dmp['project_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce submission authorization
    if (!canSubmitDmp($projectRole, $systemRole, $dmp['status'])) {
        $_SESSION['dmp_error'] = 'Access denied: You do not have permission to submit this Data Management Plan.';
        header("Location: view.php?id={$dmpId}");
        exit;
    }

    // Validate completeness of all 12 sections
    $validationErrors = validateDmpCompleteness($dmp);
    if (!empty($validationErrors)) {
        $_SESSION['edit_dmp_errors'] = array_merge(
            ['Cannot submit incomplete DMP for review. Please complete all required sections before submitting:'],
            $validationErrors
        );
        header("Location: edit.php?id={$dmpId}");
        exit;
    }

    // Update status to 'submitted'
    $updateStmt = $pdo->prepare("UPDATE data_management_plans SET status = 'submitted' WHERE id = :id");
    $updateStmt->execute([':id' => $dmpId]);

    // Record audit log
    log_audit(
        $pdo,
        'dmp_submitted',
        'data_management_plan',
        $dmpId,
        "Submitted Data Management Plan for project {$dmp['project_code']} for formal review"
    );

    // Notify project supervisors
    require_once __DIR__ . '/../notifications/notification_helper.php';
    $supStmt = $pdo->prepare("
        SELECT u.id 
        FROM project_members pm
        INNER JOIN users u ON pm.user_id = u.id
        INNER JOIN roles r ON u.role_id = r.id
        WHERE pm.project_id = ? AND r.name = 'supervisor'
    ");
    $supStmt->execute([$projectId]);
    $supervisors = $supStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($supervisors as $supId) {
        createNotification(
            $pdo,
            (int)$supId,
            'dmp_submitted',
            'DMP Submitted for Review',
            "A Data Management Plan for project '{$dmp['project_title']}' ({$dmp['project_code']}) was submitted for review.",
            'dmp',
            $dmpId
        );
    }

    $_SESSION['dmp_success'] = 'Data Management Plan submitted successfully for supervisor and data steward review!';
    header("Location: view.php?id={$dmpId}");
    exit;

} catch (PDOException $e) {
    error_log("DMP Submit Exception: " . $e->getMessage());
    $_SESSION['dmp_error'] = 'A database error occurred while submitting the DMP.';
    header("Location: view.php?id={$dmpId}");
    exit;
}
