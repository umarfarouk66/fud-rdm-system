<?php
/**
 * Archive Dataset Controller
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/preservation_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Enforce librarian / admin authorization
if (!canManagePreservation($systemRole)) {
    $_SESSION['access_error'] = 'Access denied: Archiving datasets is restricted to librarians and system administrators.';
    header('Location: ../researcher/dashboard.php');
    exit;
}

$datasetId = (int)($_GET['dataset_id'] ?? ($_POST['dataset_id'] ?? 0));

if ($datasetId <= 0) {
    $_SESSION['preservation_error'] = 'Invalid dataset specified for archival.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and active version
    $dStmt = $pdo->prepare("
        SELECT 
            d.*,
            v.file_path, v.file_size, v.checksum, v.file_name, v.version_number
        FROM datasets d
        LEFT JOIN dataset_versions v ON d.id = v.dataset_id AND d.current_version = v.version_number
        WHERE d.id = :id
        LIMIT 1
    ");
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['preservation_error'] = 'The requested dataset does not exist or has been deleted.';
        header('Location: index.php');
        exit;
    }

    if ($dataset['status'] === 'archived') {
        $_SESSION['preservation_error'] = 'This dataset is already in the archive.';
        header("Location: history.php?dataset_id={$datasetId}");
        exit;
    }

    // 2. Verify physical file exists and is intact if a file version is attached
    if (!empty($dataset['file_path'])) {
        $integrity = verifyPhysicalFileIntegrity($dataset['file_path'], $dataset['checksum'], (int)$dataset['file_size']);
        if (!$integrity['valid']) {
            $_SESSION['preservation_error'] = "Archiving blocked: Physical file integrity check failed ({$integrity['error']}).";
            header("Location: history.php?dataset_id={$datasetId}");
            exit;
        }
    }

    // 3. Database transaction (Non-destructive: physical files are preserved!)
    $pdo->beginTransaction();

    // Update dataset status to archived
    $upStmt = $pdo->prepare("UPDATE datasets SET status = 'archived', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $upStmt->execute([':id' => $datasetId]);

    // Insert archival record in preservation_records
    $archNotes = encodePreservationNotes([
        'action'              => 'archive',
        'version_number'      => (int)$dataset['version_number'],
        'file_name'           => $dataset['file_name'],
        'verified_checksum'   => $dataset['checksum'],
        'location'            => 'Long-Term Digital Archive',
        'preservation_notes'  => 'Dataset officially transitioned to long-term institutional archive status.',
        'archived_by'         => $userId,
        'archived_at'         => date('Y-m-d H:i:s')
    ]);

    $insStmt = $pdo->prepare("
        INSERT INTO preservation_records (
            dataset_id,
            action,
            notes,
            performed_by,
            performed_at
        ) VALUES (
            :dataset_id,
            'archive',
            :notes,
            :performed_by,
            CURRENT_TIMESTAMP
        )
    ");
    $insStmt->execute([
        ':dataset_id'   => $datasetId,
        ':notes'        => $archNotes,
        ':performed_by' => $userId
    ]);

    $recordId = (int)$pdo->lastInsertId();

    // Record audit log
    log_audit(
        $pdo,
        'dataset_archived',
        'datasets',
        $datasetId,
        "Librarian '{$user['name']}' (#{$userId}) archived dataset '{$dataset['title']}' (#{$datasetId}) to Long-Term Archive (physical file preserved)"
    );

    // Notify dataset owner
    require_once __DIR__ . '/../notifications/notification_helper.php';
    createNotification(
        $pdo,
        (int)$dataset['owner_id'],
        'dataset_archived',
        'Dataset Archived',
        "Your dataset '{$dataset['title']}' has been moved to institutional long-term archiving.",
        'preservation',
        $datasetId
    );

    $pdo->commit();

    $_SESSION['preservation_success'] = "Dataset '{$dataset['title']}' has been moved to the long-term archive. Files remain safely stored.";
    header("Location: history.php?dataset_id={$datasetId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Dataset Archive Exception: " . $e->getMessage());
    $_SESSION['preservation_error'] = 'Database error archiving dataset.';
    header('Location: index.php');
    exit;
}
