<?php
/**
 * Store New Dataset Controller
 * RDM Information System - Step 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';

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
    $_SESSION['create_dataset_errors'] = ['Please select a valid research project.'];
    header('Location: create.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['create_dataset_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: create.php?project_id={$projectId}");
    exit;
}

// Sanitize text inputs
$title       = sanitize_input($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$datasetType = sanitize_input($_POST['dataset_type'] ?? '');
$accessLevel = strtolower(trim($_POST['access_level'] ?? 'private'));

$_SESSION['old_dataset_data'] = [
    'project_id'   => $projectId,
    'title'        => $title,
    'description'  => $description,
    'dataset_type' => $datasetType,
    'access_level' => $accessLevel
];

$errors = [];

// Validation: Required fields
if (empty($title)) {
    $errors[] = 'Dataset title is required.';
} elseif (strlen($title) > 255) {
    $errors[] = 'Dataset title cannot exceed 255 characters.';
}

if (empty($description)) {
    $errors[] = 'Dataset description is required.';
}

if (empty($datasetType)) {
    $errors[] = 'Dataset type is required.';
}

$allowedAccessLevels = ['private', 'restricted', 'public'];
if (!in_array($accessLevel, $allowedAccessLevels, true)) {
    $errors[] = 'Invalid repository access level selected.';
}

// 1. Verify Project and Authorization
try {
    $projStmt = $pdo->prepare("SELECT id, project_code, title, owner_id FROM research_projects WHERE id = :id LIMIT 1");
    $projStmt->execute([':id' => $projectId]);
    $project = $projStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $errors[] = 'The selected research project does not exist.';
    } else {
        $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
        if (!canManageMembers($projectRole, $systemRole)) {
            $_SESSION['dataset_error'] = 'Access denied: You do not have permission to deposit datasets for this project.';
            header('Location: index.php');
            exit;
        }

        // 2. Check DMP Prerequisite
        $dmpStmt = $pdo->prepare("SELECT id FROM data_management_plans WHERE project_id = :project_id LIMIT 1");
        $dmpStmt->execute([':project_id' => $projectId]);
        if (!$dmpStmt->fetch()) {
            $errors[] = 'A Data Management Plan must be created before depositing a dataset for this research project.';
        }
    }
} catch (PDOException $e) {
    error_log("Dataset Store Project Verification Error: " . $e->getMessage());
    $errors[] = 'A database error occurred while verifying the project.';
}

// 3. Validate Uploaded File
$fileValidation = validateUploadedFile($_FILES['dataset_file'] ?? []);
if (!$fileValidation['valid']) {
    $errors[] = $fileValidation['error'];
}

if (!empty($errors)) {
    $_SESSION['create_dataset_errors'] = $errors;
    header("Location: create.php?project_id={$projectId}");
    exit;
}

$savedFile = null;

try {
    // 4. Save uploaded file securely in storage/datasets/
    $savedFile = saveUploadedDatasetFile($fileValidation['temp_path'], $fileValidation['extension']);

    // 5. Database transaction
    $pdo->beginTransaction();

    // Insert into datasets
    $datasetSql = "
        INSERT INTO datasets (
            project_id,
            owner_id,
            title,
            description,
            dataset_type,
            access_level,
            status,
            current_version,
            total_size,
            download_count,
            view_count
        ) VALUES (
            :project_id,
            :owner_id,
            :title,
            :description,
            :dataset_type,
            :access_level,
            'draft',
            1,
            :total_size,
            0,
            0
        )
    ";

    $datasetStmt = $pdo->prepare($datasetSql);
    $datasetStmt->execute([
        ':project_id'   => $projectId,
        ':owner_id'     => $userId,
        ':title'        => $title,
        ':description'  => $description,
        ':dataset_type' => $datasetType,
        ':access_level' => $accessLevel,
        ':total_size'   => $fileValidation['size']
    ]);

    $datasetId = (int)$pdo->lastInsertId();

    // Insert Version 1 record
    $versionSql = "
        INSERT INTO dataset_versions (
            dataset_id,
            version_number,
            file_name,
            stored_file_name,
            file_path,
            file_size,
            mime_type,
            checksum,
            uploaded_by,
            version_notes
        ) VALUES (
            :dataset_id,
            1,
            :file_name,
            :stored_file_name,
            :file_path,
            :file_size,
            :mime_type,
            :checksum,
            :uploaded_by,
            'Initial dataset deposit (Version 1)'
        )
    ";

    $versionStmt = $pdo->prepare($versionSql);
    $versionStmt->execute([
        ':dataset_id'        => $datasetId,
        ':file_name'         => $fileValidation['original_name'],
        ':stored_file_name'  => $savedFile['stored_file_name'],
        ':file_path'         => $savedFile['file_path'],
        ':file_size'         => $fileValidation['size'],
        ':mime_type'         => $fileValidation['mime'],
        ':checksum'          => $savedFile['checksum'],
        ':uploaded_by'       => $userId
    ]);

    // Record audit log
    log_audit(
        $pdo,
        'dataset_created',
        'dataset',
        $datasetId,
        "Deposited dataset '{$title}' ({$fileValidation['original_name']}, " . formatFileSize($fileValidation['size']) . ") with initial version 1"
    );

    $pdo->commit();

    // Clear old form session data
    unset($_SESSION['old_dataset_data']);
    unset($_SESSION['create_dataset_errors']);

    $_SESSION['dataset_success'] = "Dataset '{$title}' deposited successfully with initial Version 1!";
    header("Location: view.php?id={$datasetId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Clean up physical file if saved
    if ($savedFile && file_exists($savedFile['full_path'])) {
        unlink($savedFile['full_path']);
    }
    error_log("Dataset Store Exception: " . $e->getMessage());
    $_SESSION['create_dataset_errors'] = ['A database error occurred while creating the dataset. Please try again.'];
    header("Location: create.php?project_id={$projectId}");
    exit;
}
