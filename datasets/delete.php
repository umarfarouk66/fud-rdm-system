<?php
/**
 * Delete Dataset Controller
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
    $_SESSION['dataset_error'] = 'Security validation failed (invalid CSRF token).';
    header("Location: view.php?id={$datasetId}");
    exit;
}

try {
    // 1. Fetch dataset and project
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

    // 2. Enforce deletion authorization (Dataset Owner / Project Owner / Admin)
    if (!canDeleteDataset($projectRole, $systemRole, $ownerId, $userId)) {
        $_SESSION['dataset_error'] = 'Access denied: Only the dataset owner or project lead can delete this dataset.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

    // 3. Enforce Preservation & Archiving Deletion Protection (Step 10)
    require_once __DIR__ . '/../preservation/preservation_helpers.php';
    if (isDatasetPreservedOrArchived($pdo, $datasetId)) {
        $_SESSION['dataset_error'] = 'This dataset has been preserved/archived and cannot be deleted through the normal dataset deletion process.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

    // 3. Find all version files to remove physically
    $vStmt = $pdo->prepare("SELECT stored_file_name FROM dataset_versions WHERE dataset_id = :dataset_id");
    $vStmt->execute([':dataset_id' => $datasetId]);
    $versionFiles = $vStmt->fetchAll(PDO::FETCH_COLUMN);

    $storageDir = realpath(__DIR__ . '/../storage/datasets');

    // 4. Record audit log before deletion
    log_audit(
        $pdo,
        'dataset_deleted',
        'dataset',
        $datasetId,
        "Deleted dataset '{$dataset['title']}' (#{$datasetId}) and purged " . count($versionFiles) . " version files"
    );

    // 5. Delete database record (foreign key cascades will remove dataset_versions and access logs)
    $delStmt = $pdo->prepare("DELETE FROM datasets WHERE id = :id");
    $delStmt->execute([':id' => $datasetId]);

    // 6. Securely delete physical version files from disk
    if ($storageDir) {
        foreach ($versionFiles as $fileName) {
            $safeName = basename($fileName);
            $fullPath = $storageDir . DIRECTORY_SEPARATOR . $safeName;
            if (file_exists($fullPath) && is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    $_SESSION['dataset_success'] = "Dataset '{$dataset['title']}' and all version files permanently deleted.";
    header('Location: index.php');
    exit;

} catch (PDOException $e) {
    error_log("Dataset Delete Exception: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'A database error occurred while deleting the dataset.';
    header("Location: view.php?id={$datasetId}");
    exit;
}
