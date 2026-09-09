<?php
/**
 * Controlled Dataset Download Endpoint
 * RDM Information System - Step 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';

// Allow public downloads for public datasets
$isGuest = !isLoggedIn();
$user = $isGuest ? null : currentUser();
$userId = $user ? (int)$user['id'] : 0;
$systemRole = $user ? $user['role'] : 'guest';

$datasetId = (int)($_GET['id'] ?? 0);
$versionNumber = (int)($_GET['version'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['dataset_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and project details
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.id AS project_id
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        $_SESSION['dataset_error'] = 'The requested dataset does not exist.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // 2. Enforce download authorization
    if (!canDownloadDataset($projectRole, $systemRole, $dataset['access_level'], $ownerId, $userId, $pdo, $datasetId)) {
        if ($isGuest) {
            $_SESSION['auth_error'] = 'Login is required to access restricted dataset downloads.';
            header('Location: ../public/login.php');
            exit;
        }
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to download this dataset.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

    // Default to current version if not specified
    if ($versionNumber <= 0) {
        $versionNumber = (int)$dataset['current_version'];
    }

    // 3. Fetch version record
    $vStmt = $pdo->prepare("
        SELECT * 
        FROM dataset_versions 
        WHERE dataset_id = :dataset_id AND version_number = :version_number
        LIMIT 1
    ");
    $vStmt->execute([
        ':dataset_id'      => $datasetId,
        ':version_number'  => $versionNumber
    ]);
    $version = $vStmt->fetch(PDO::FETCH_ASSOC);

    if (!$version) {
        $_SESSION['dataset_error'] = "Dataset Version {$versionNumber} not found.";
        header("Location: view.php?id={$datasetId}");
        exit;
    }

    // 4. Resolve physical file path safely
    $storageDir = realpath(__DIR__ . '/../storage/datasets');
    $storedFileName = basename($version['stored_file_name']);
    $physicalFilePath = $storageDir . DIRECTORY_SEPARATOR . $storedFileName;

    if (!file_exists($physicalFilePath) || !is_readable($physicalFilePath)) {
        error_log("Physical file missing at: " . $physicalFilePath);
        $_SESSION['dataset_error'] = 'The requested dataset file is missing from physical storage.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

    // 5. Update metrics & record access logs
    $pdo->prepare("UPDATE datasets SET download_count = download_count + 1 WHERE id = :id")->execute([':id' => $datasetId]);
    log_dataset_access($pdo, $datasetId, $userId, 'download');
    log_audit(
        $pdo,
        'dataset_downloaded',
        'dataset',
        $datasetId,
        "Downloaded version {$versionNumber} ({$version['file_name']})"
    );

    // 6. Send file download stream
    if (ob_get_level()) {
        ob_end_clean();
    }

    $downloadFilename = $version['file_name'];
    $mimeType = $version['mime_type'] ?: 'application/octet-stream';
    $fileSize = filesize($physicalFilePath);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadFilename) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);

    readfile($physicalFilePath);
    exit;

} catch (Exception $e) {
    error_log("Dataset Download Exception: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'An unexpected error occurred during download.';
    header('Location: index.php');
    exit;
}
