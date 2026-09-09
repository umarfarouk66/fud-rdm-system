<?php
/**
 * Update Dataset Metadata Controller
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

$metadataId = (int)($_POST['metadata_id'] ?? 0);

if ($metadataId <= 0) {
    $_SESSION['metadata_error'] = 'Invalid metadata identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['edit_metadata_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: edit.php?id={$metadataId}");
    exit;
}

// Fetch existing metadata and associated dataset
try {
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.owner_id AS dataset_owner_id,
            p.id AS project_id
        FROM dataset_metadata m
        INNER JOIN datasets d ON m.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $metadataId]);
    $metadata = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metadata) {
        $_SESSION['metadata_error'] = 'The requested metadata record was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$metadata['project_id'];
    $datasetOwnerId = (int)$metadata['dataset_owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce authorization
    if (!canManageDatasetMetadata($projectRole, $systemRole, $datasetOwnerId, $userId)) {
        $_SESSION['metadata_error'] = 'Access denied: You do not have permission to modify metadata for this dataset.';
        header("Location: view.php?id={$metadataId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Metadata Update Auth Error: " . $e->getMessage());
    $_SESSION['metadata_error'] = 'Database error verifying metadata authorization.';
    header('Location: index.php');
    exit;
}

// Extract form data
$formData = [
    'id'                       => $metadataId,
    'dataset_id'               => (int)$metadata['dataset_id'],
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

$_SESSION['old_edit_metadata_data'] = $formData;

$validationErrors = validateMetadataForm($formData);
if (!empty($validationErrors)) {
    $_SESSION['edit_metadata_errors'] = $validationErrors;
    header("Location: edit.php?id={$metadataId}");
    exit;
}

try {
    $updateSql = "
        UPDATE dataset_metadata SET
            creator                  = :creator,
            keywords                 = :keywords,
            subject_area             = :subject_area,
            creation_date            = :creation_date,
            modification_date        = :modification_date,
            collection_methodology   = :collection_methodology,
            geographic_coverage      = :geographic_coverage,
            data_type                = :data_type,
            file_format              = :file_format,
            related_publication      = :related_publication,
            funding_source           = :funding_source,
            license                  = :license,
            access_conditions        = :access_conditions,
            processing_documentation = :processing_documentation,
            updated_at               = CURRENT_TIMESTAMP
        WHERE id = :id
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
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
        ':processing_documentation' => $formData['processing_documentation'],
        ':id'                       => $metadataId
    ]);

    // Record audit log
    log_audit(
        $pdo,
        'metadata_updated',
        'dataset_metadata',
        $metadataId,
        "Updated descriptive metadata for dataset '{$metadata['dataset_title']}' (#{$metadata['dataset_id']})"
    );

    unset($_SESSION['old_edit_metadata_data']);
    unset($_SESSION['edit_metadata_errors']);

    $_SESSION['metadata_success'] = 'Dataset metadata updated successfully!';
    header("Location: view.php?id={$metadataId}");
    exit;

} catch (PDOException $e) {
    error_log("Metadata Update Exception: " . $e->getMessage());
    $_SESSION['edit_metadata_errors'] = ['A database error occurred while updating metadata. Please try again.'];
    header("Location: edit.php?id={$metadataId}");
    exit;
}
