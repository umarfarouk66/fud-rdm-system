<?php
/**
 * Store Dataset Metadata Controller
 * RDM Information System - Step 8
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';

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
    $_SESSION['create_metadata_errors'] = ['Please select a valid dataset.'];
    header('Location: create.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['create_metadata_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: create.php?dataset_id={$datasetId}");
    exit;
}

// Extract form data
$formData = [
    'dataset_id'               => $datasetId,
    'creator'                  => sanitize_input($_POST['creator'] ?? ''),
    'keywords'                 => trim($_POST['keywords'] ?? ''),
    'subject_area'             => sanitize_input($_POST['subject_area'] ?? ''),
    'creation_date'            => trim($_POST['creation_date'] ?? ''),
    'modification_date'        => trim($_POST['modification_date'] ?? ''),
    'collection_methodology'   => trim($_POST['collection_methodology'] ?? ''),
    'geographic_coverage'      => sanitize_input($_POST['geographic_coverage'] ?? ''),
    'data_type'                => sanitize_input($_POST['data_type'] ?? ''),
    'file_format'              => sanitize_input($_POST['file_format'] ?? ''),
    'related_publication'      => trim($_POST['related_publication'] ?? ''),
    'funding_source'           => trim($_POST['funding_source'] ?? ''),
    'license'                  => sanitize_input($_POST['license'] ?? ''),
    'access_conditions'        => trim($_POST['access_conditions'] ?? ''),
    'processing_documentation' => trim($_POST['processing_documentation'] ?? '')
];

$_SESSION['old_metadata_data'] = $formData;

try {
    // 1. Verify dataset exists and fetch project
    $dStmt = $pdo->prepare("
        SELECT 
            d.id, d.title, d.owner_id, d.project_id,
            p.project_code
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        $_SESSION['create_metadata_errors'] = ['The selected dataset does not exist.'];
        header('Location: create.php');
        exit;
    }

    // 2. Check authorization
    $projectRole = getProjectMemberRole($pdo, (int)$dataset['project_id'], $userId);
    if (!canManageDatasetMetadata($projectRole, $systemRole, (int)$dataset['owner_id'], $userId)) {
        $_SESSION['metadata_error'] = 'Access denied: You do not have permission to create metadata for this dataset.';
        header('Location: index.php');
        exit;
    }

    // 3. Check duplicate metadata
    $checkDup = $pdo->prepare("SELECT id FROM dataset_metadata WHERE dataset_id = :dataset_id LIMIT 1");
    $checkDup->execute([':dataset_id' => $datasetId]);
    $existing = $checkDup->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        unset($_SESSION['old_metadata_data']);
        $_SESSION['metadata_error'] = 'Metadata already exists for this dataset.';
        header("Location: view.php?id={$existing['id']}");
        exit;
    }

    // 4. Validate form inputs
    $validationErrors = validateMetadataForm($formData);
    if (!empty($validationErrors)) {
        $_SESSION['create_metadata_errors'] = $validationErrors;
        header("Location: create.php?dataset_id={$datasetId}");
        exit;
    }

    // 5. Database transaction
    $pdo->beginTransaction();

    $insertSql = "
        INSERT INTO dataset_metadata (
            dataset_id,
            creator,
            keywords,
            subject_area,
            creation_date,
            modification_date,
            collection_methodology,
            geographic_coverage,
            data_type,
            file_format,
            related_publication,
            funding_source,
            license,
            access_conditions,
            processing_documentation
        ) VALUES (
            :dataset_id,
            :creator,
            :keywords,
            :subject_area,
            :creation_date,
            :modification_date,
            :collection_methodology,
            :geographic_coverage,
            :data_type,
            :file_format,
            :related_publication,
            :funding_source,
            :license,
            :access_conditions,
            :processing_documentation
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':dataset_id'               => $datasetId,
        ':creator'                  => $formData['creator'],
        ':keywords'                 => $formData['keywords'],
        ':subject_area'             => $formData['subject_area'],
        ':creation_date'            => $formData['creation_date'],
        ':modification_date'        => !empty($formData['modification_date']) ? $formData['modification_date'] : null,
        ':collection_methodology'   => $formData['collection_methodology'],
        ':geographic_coverage'      => $formData['geographic_coverage'],
        ':data_type'                => $formData['data_type'],
        ':file_format'              => $formData['file_format'],
        ':related_publication'      => !empty($formData['related_publication']) ? $formData['related_publication'] : null,
        ':funding_source'           => !empty($formData['funding_source']) ? $formData['funding_source'] : null,
        ':license'                  => $formData['license'],
        ':access_conditions'        => $formData['access_conditions'],
        ':processing_documentation' => $formData['processing_documentation']
    ]);

    $metadataId = (int)$pdo->lastInsertId();

    // Record audit log
    log_audit(
        $pdo,
        'metadata_created',
        'dataset_metadata',
        $metadataId,
        "Created descriptive metadata for dataset '{$dataset['title']}' (#{$datasetId})"
    );

    $pdo->commit();

    unset($_SESSION['old_metadata_data']);
    unset($_SESSION['create_metadata_errors']);

    $_SESSION['metadata_success'] = 'Dataset descriptive metadata created successfully!';
    header("Location: view.php?id={$metadataId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Metadata Store Exception: " . $e->getMessage());
    $_SESSION['create_metadata_errors'] = ['A database error occurred while saving metadata. Please try again.'];
    header("Location: create.php?dataset_id={$datasetId}");
    exit;
}
