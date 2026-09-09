<?php
/**
 * Store Dataset Preservation Record Controller
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/preservation_helpers.php';

// Enforce authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Enforce librarian / admin authorization
if (!canManagePreservation($systemRole)) {
    $_SESSION['access_error'] = 'Access denied: You do not have permission to execute dataset preservation.';
    header('Location: ../researcher/dashboard.php');
    exit;
}

$datasetId = (int)($_POST['dataset_id'] ?? 0);
$versionNumber = (int)($_POST['version_number'] ?? 0);
$preservationLocation = sanitize_input($_POST['preservation_location'] ?? '');
$preservationNotes = trim($_POST['preservation_notes'] ?? '');

if ($datasetId <= 0 || $versionNumber <= 0) {
    $_SESSION['preservation_error'] = 'Invalid dataset or version identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['preservation_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: create.php?dataset_id={$datasetId}&version={$versionNumber}");
    exit;
}

$_SESSION['old_preservation_data'] = [
    'preservation_location' => $preservationLocation,
    'preservation_notes'    => $preservationNotes
];

// Validate inputs
$errors = [];
if (empty($preservationLocation) || !array_key_exists($preservationLocation, PRESERVATION_LOCATIONS)) {
    $errors[] = 'Please select a valid preservation location tier.';
}
if (empty($preservationNotes)) {
    $errors[] = 'Please provide preservation notes.';
}

if (!empty($errors)) {
    $_SESSION['preservation_errors'] = $errors;
    header("Location: create.php?dataset_id={$datasetId}&version={$versionNumber}");
    exit;
}

try {
    // 1. Fetch dataset and specific version details
    $dStmt = $pdo->prepare("
        SELECT 
            d.id, d.title, d.status AS dataset_status,
            v.id AS version_id, v.version_number, v.file_name, v.file_path, v.file_size, v.mime_type, v.checksum
        FROM datasets d
        INNER JOIN dataset_versions v ON d.id = v.dataset_id AND v.version_number = :version_number
        WHERE d.id = :id
        LIMIT 1
    ");
    $dStmt->execute([
        ':id'             => $datasetId,
        ':version_number' => $versionNumber
    ]);
    $target = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target || $target['dataset_status'] === 'deleted') {
        $_SESSION['preservation_errors'] = ['The target dataset version was not found or has been deleted.'];
        header("Location: create.php?dataset_id={$datasetId}&version={$versionNumber}");
        exit;
    }

    // 2. Perform live cryptographic checksum and physical file verification
    $integrity = verifyPhysicalFileIntegrity($target['file_path'], $target['checksum'], (int)$target['file_size']);

    if (!$integrity['valid']) {
        // Record preservation failure in audit logs
        log_audit(
            $pdo,
            'preservation_failed',
            'datasets',
            $datasetId,
            "Integrity verification failed during preservation attempt for dataset '{$target['title']}' v{$versionNumber}: {$integrity['error']}"
        );

        $_SESSION['preservation_errors'] = [
            "Preservation aborted: {$integrity['error']}"
        ];
        header("Location: create.php?dataset_id={$datasetId}&version={$versionNumber}");
        exit;
    }

    // 3. Prepare structured preservation notes
    $structuredNotes = encodePreservationNotes([
        'version_number'      => (int)$target['version_number'],
        'version_id'          => (int)$target['version_id'],
        'file_name'           => $target['file_name'],
        'file_size'           => (int)$target['file_size'],
        'mime_type'           => $target['mime_type'],
        'baseline_checksum'   => $target['checksum'],
        'verified_checksum'   => $integrity['actual_checksum'],
        'location'            => $preservationLocation,
        'verification_status' => 'verified',
        'preservation_notes'  => $preservationNotes,
        'verified_by'         => $userId,
        'verified_at'         => date('Y-m-d H:i:s')
    ]);

    // 4. Execute database transaction
    $pdo->beginTransaction();

    $insStmt = $pdo->prepare("
        INSERT INTO preservation_records (
            dataset_id,
            action,
            notes,
            performed_by,
            performed_at
        ) VALUES (
            :dataset_id,
            'preserve',
            :notes,
            :performed_by,
            CURRENT_TIMESTAMP
        )
    ");
    $insStmt->execute([
        ':dataset_id'   => $datasetId,
        ':notes'        => $structuredNotes,
        ':performed_by' => $userId
    ]);

    $preservationId = (int)$pdo->lastInsertId();

    // Update dataset status to preserved
    $upStmt = $pdo->prepare("UPDATE datasets SET status = 'preserved', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $upStmt->execute([':id' => $datasetId]);

    // Record audit log
    log_audit(
        $pdo,
        'preservation_created',
        'preservation_records',
        $preservationId,
        "Librarian '{$user['name']}' (#{$userId}) preserved dataset '{$target['title']}' (v{$versionNumber}) at location '{$preservationLocation}' with verified SHA-256 [{$integrity['actual_checksum']}]"
    );

    // Notify dataset owner
    require_once __DIR__ . '/../notifications/notification_helper.php';
    createNotification(
        $pdo,
        (int)$target['owner_id'],
        'preservation_created',
        'Dataset Preserved',
        "Your dataset '{$target['title']}' (v{$versionNumber}) has been cryptographically preserved in long-term storage.",
        'preservation',
        $datasetId
    );

    $pdo->commit();

    unset($_SESSION['old_preservation_data']);
    unset($_SESSION['preservation_errors']);

    $_SESSION['preservation_success'] = "Dataset version {$versionNumber} successfully verified and preserved in {$preservationLocation}.";
    header("Location: view.php?id={$preservationId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Preservation Store Exception: " . $e->getMessage());
    $_SESSION['preservation_errors'] = ['A system error occurred while executing preservation. Please try again.'];
    header("Location: create.php?dataset_id={$datasetId}&version={$versionNumber}");
    exit;
}
