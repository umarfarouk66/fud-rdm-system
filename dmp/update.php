<?php
/**
 * Update Data Management Plan Controller
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
    $_SESSION['edit_dmp_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: edit.php?id={$dmpId}");
    exit;
}

// Fetch existing DMP
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

    // Enforce authorization
    if (!canManageMembers($projectRole, $systemRole)) {
        $_SESSION['dmp_error'] = 'Access denied: You do not have permission to modify this Data Management Plan.';
        header("Location: view.php?id={$dmpId}");
        exit;
    }

    // Enforce status lock: only draft or rejected can be edited
    if (!canEditDmp($projectRole, $systemRole, $dmp['status'])) {
        $_SESSION['dmp_error'] = "This DMP is in '{$dmp['status']}' status and cannot be modified.";
        header("Location: view.php?id={$dmpId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("DMP Update Auth Error: " . $e->getMessage());
    $_SESSION['dmp_error'] = 'Database error verifying DMP authorization.';
    header('Location: index.php');
    exit;
}

// Extract form data
$submitAction = strtolower(trim($_POST['submit_action'] ?? 'draft'));

$formData = [
    'id'                      => $dmpId,
    'project_id'              => $projectId,
    'data_description'        => trim($_POST['data_description'] ?? ''),
    'data_type'               => trim($_POST['data_type'] ?? ''),
    'expected_volume'         => trim($_POST['expected_volume'] ?? ''),
    'data_organisation'       => trim($_POST['data_organisation'] ?? ''),
    'storage_location'        => trim($_POST['storage_location'] ?? ''),
    'protection_measures'     => trim($_POST['protection_measures'] ?? ''),
    'access_control'          => trim($_POST['access_control'] ?? ''),
    'sensitive_data_handling' => trim($_POST['sensitive_data_handling'] ?? ''),
    'sharing_plan'            => trim($_POST['sharing_plan'] ?? ''),
    'retention_period'        => trim($_POST['retention_period'] ?? ''),
    'preservation_plan'       => trim($_POST['preservation_plan'] ?? ''),
    'disposal_plan'           => trim($_POST['disposal_plan'] ?? '')
];

$_SESSION['old_edit_dmp_data'] = $formData;

if ($submitAction === 'submit') {
    $validationErrors = validateDmpCompleteness($formData);
    if (!empty($validationErrors)) {
        $_SESSION['edit_dmp_errors'] = array_merge(
            ['Cannot submit incomplete DMP for review. Please complete all required sections:'],
            $validationErrors
        );
        header("Location: edit.php?id={$dmpId}");
        exit;
    }
    $newStatus = 'submitted';
    $auditAction = 'dmp_submitted';
    $auditDesc = "Submitted Data Management Plan for project {$dmp['project_code']} for formal review";
    $successFlash = "Data Management Plan successfully updated and submitted for supervisor review!";
} else {
    $newStatus = 'draft';
    $auditAction = 'dmp_updated';
    $auditDesc = "Updated draft Data Management Plan for project {$dmp['project_code']}";
    $successFlash = "Data Management Plan draft changes saved successfully.";
}

try {
    $updateSql = "
        UPDATE data_management_plans SET
            data_description        = :data_description,
            data_type               = :data_type,
            expected_volume         = :expected_volume,
            data_organisation       = :data_organisation,
            storage_location        = :storage_location,
            protection_measures     = :protection_measures,
            access_control          = :access_control,
            sensitive_data_handling = :sensitive_data_handling,
            sharing_plan            = :sharing_plan,
            retention_period        = :retention_period,
            preservation_plan       = :preservation_plan,
            disposal_plan           = :disposal_plan,
            status                  = :status
        WHERE id = :id
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':id'                      => $dmpId,
        ':data_description'        => !empty($formData['data_description']) ? $formData['data_description'] : null,
        ':data_type'               => !empty($formData['data_type']) ? $formData['data_type'] : null,
        ':expected_volume'         => !empty($formData['expected_volume']) ? $formData['expected_volume'] : null,
        ':data_organisation'       => !empty($formData['data_organisation']) ? $formData['data_organisation'] : null,
        ':storage_location'        => !empty($formData['storage_location']) ? $formData['storage_location'] : null,
        ':protection_measures'     => !empty($formData['protection_measures']) ? $formData['protection_measures'] : null,
        ':access_control'          => !empty($formData['access_control']) ? $formData['access_control'] : null,
        ':sensitive_data_handling' => !empty($formData['sensitive_data_handling']) ? $formData['sensitive_data_handling'] : null,
        ':sharing_plan'            => !empty($formData['sharing_plan']) ? $formData['sharing_plan'] : null,
        ':retention_period'        => !empty($formData['retention_period']) ? $formData['retention_period'] : null,
        ':preservation_plan'       => !empty($formData['preservation_plan']) ? $formData['preservation_plan'] : null,
        ':disposal_plan'           => !empty($formData['disposal_plan']) ? $formData['disposal_plan'] : null,
        ':status'                  => $newStatus
    ]);

    // Record audit log
    log_audit($pdo, $auditAction, 'data_management_plan', $dmpId, $auditDesc);

    // Clear old edit session data
    unset($_SESSION['old_edit_dmp_data']);
    unset($_SESSION['edit_dmp_errors']);

    $_SESSION['dmp_success'] = $successFlash;
    header("Location: view.php?id={$dmpId}");
    exit;

} catch (PDOException $e) {
    error_log("DMP Update Exception: " . $e->getMessage());
    $_SESSION['edit_dmp_errors'] = ['A database error occurred while updating the DMP. Please try again.'];
    header("Location: edit.php?id={$dmpId}");
    exit;
}
