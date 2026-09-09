<?php
/**
 * Update Dataset Controller
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

$datasetId = (int)($_POST['dataset_id'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['dataset_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['edit_dataset_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: edit.php?id={$datasetId}");
    exit;
}

// Fetch existing dataset
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.project_code,
            p.title AS project_title
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        $_SESSION['dataset_error'] = 'The requested dataset was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce authorization
    if (!canEditDataset($projectRole, $systemRole, $ownerId, $userId)) {
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to modify this dataset.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Dataset Update Auth Error: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'Database error verifying dataset authorization.';
    header('Location: index.php');
    exit;
}

// Sanitize inputs
$title       = sanitize_input($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$datasetType = sanitize_input($_POST['dataset_type'] ?? '');
$accessLevel = strtolower(trim($_POST['access_level'] ?? 'private'));

$_SESSION['old_edit_dataset_data'] = [
    'id'           => $datasetId,
    'title'        => $title,
    'description'  => $description,
    'dataset_type' => $datasetType,
    'access_level' => $accessLevel
];

$errors = [];

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

if (!empty($errors)) {
    $_SESSION['edit_dataset_errors'] = $errors;
    header("Location: edit.php?id={$datasetId}");
    exit;
}

try {
    $updateSql = "
        UPDATE datasets SET
            title        = :title,
            description  = :description,
            dataset_type = :dataset_type,
            access_level = :access_level,
            updated_at   = CURRENT_TIMESTAMP
        WHERE id = :id
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':title'        => $title,
        ':description'  => $description,
        ':dataset_type' => $datasetType,
        ':access_level' => $accessLevel,
        ':id'           => $datasetId
    ]);

    // Record audit log
    log_audit(
        $pdo,
        'dataset_updated',
        'dataset',
        $datasetId,
        "Updated metadata and access level ({$accessLevel}) for dataset '{$title}'"
    );

    unset($_SESSION['old_edit_dataset_data']);
    unset($_SESSION['edit_dataset_errors']);

    $_SESSION['dataset_success'] = 'Dataset metadata updated successfully.';
    header("Location: view.php?id={$datasetId}");
    exit;

} catch (PDOException $e) {
    error_log("Dataset Update Exception: " . $e->getMessage());
    $_SESSION['edit_dataset_errors'] = ['A database error occurred while updating the dataset. Please try again.'];
    header("Location: edit.php?id={$datasetId}");
    exit;
}
