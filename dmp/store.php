<?php
/**
 * Store Data Management Plan Controller
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

$projectId = (int)($_POST['project_id'] ?? 0);

if ($projectId <= 0) {
    $_SESSION['create_dmp_errors'] = ['Please select a valid research project.'];
    header('Location: create.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['create_dmp_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: create.php?project_id={$projectId}");
    exit;
}

// Extract form data
$submitAction = strtolower(trim($_POST['submit_action'] ?? 'draft'));

$formData = [
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

$_SESSION['old_dmp_data'] = $formData;

try {
    // 1. Verify project exists
    $projStmt = $pdo->prepare("SELECT id, project_code, title, owner_id FROM research_projects WHERE id = :id LIMIT 1");
    $projStmt->execute([':id' => $projectId]);
    $project = $projStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['create_dmp_errors'] = ['The selected research project does not exist.'];
        header('Location: create.php');
        exit;
    }

    // 2. Check authorization: must be owner, manager, or admin
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    if (!canManageMembers($projectRole, $systemRole)) {
        $_SESSION['dmp_error'] = 'Access denied: You do not have permission to create a DMP for this project.';
        header('Location: index.php');
        exit;
    }

    // 3. Check duplicate DMP for project
    $dupStmt = $pdo->prepare("SELECT id FROM data_management_plans WHERE project_id = :project_id LIMIT 1");
    $dupStmt->execute([':project_id' => $projectId]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        unset($_SESSION['old_dmp_data']);
        $_SESSION['dmp_error'] = 'This research project already has an existing Data Management Plan.';
        header("Location: view.php?id={$existing['id']}");
        exit;
    }

    // 4. Validate completeness if submitting for review
    if ($submitAction === 'submit') {
        $validationErrors = validateDmpCompleteness($formData);
        if (!empty($validationErrors)) {
            $_SESSION['create_dmp_errors'] = array_merge(
                ['Cannot submit incomplete DMP for review. Please complete all required sections:'],
                $validationErrors
            );
            header("Location: create.php?project_id={$projectId}");
            exit;
        }
        $status = 'submitted';
        $auditAction = 'dmp_submitted';
        $auditDesc = "Created and submitted Data Management Plan for project {$project['project_code']}";
        $successFlash = "Data Management Plan successfully created and submitted for supervisor review!";
    } else {
        $status = 'draft';
        $auditAction = 'dmp_created';
        $auditDesc = "Created draft Data Management Plan for project {$project['project_code']}";
        $successFlash = "Data Management Plan draft saved successfully.";
    }

    // 5. Database transaction
    $pdo->beginTransaction();

    $insertSql = "
        INSERT INTO data_management_plans (
            project_id,
            data_description,
            data_type,
            expected_volume,
            data_organisation,
            storage_location,
            protection_measures,
            access_control,
            sensitive_data_handling,
            sharing_plan,
            retention_period,
            preservation_plan,
            disposal_plan,
            status
        ) VALUES (
            :project_id,
            :data_description,
            :data_type,
            :expected_volume,
            :data_organisation,
            :storage_location,
            :protection_measures,
            :access_control,
            :sensitive_data_handling,
            :sharing_plan,
            :retention_period,
            :preservation_plan,
            :disposal_plan,
            :status
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':project_id'              => $projectId,
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
        ':status'                  => $status
    ]);

    $dmpId = (int)$pdo->lastInsertId();

    // Log audit
    log_audit($pdo, $auditAction, 'data_management_plan', $dmpId, $auditDesc);

    $pdo->commit();

    // Clear old form session data
    unset($_SESSION['old_dmp_data']);
    unset($_SESSION['create_dmp_errors']);

    $_SESSION['dmp_success'] = $successFlash;
    header("Location: view.php?id={$dmpId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("DMP Store Exception: " . $e->getMessage());
    $_SESSION['create_dmp_errors'] = ['A database error occurred while saving the DMP. Please try again.'];
    header("Location: create.php?project_id={$projectId}");
    exit;
}
