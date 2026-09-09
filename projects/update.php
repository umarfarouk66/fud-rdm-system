<?php
/**
 * Update Research Project Controller
 * RDM Information System - Step 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

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
    $_SESSION['project_error'] = 'Invalid project identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF token
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token). Please try again.';
    header("Location: edit.php?id={$projectId}");
    exit;
}

// Fetch project to ensure existence
try {
    $stmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :project_id LIMIT 1");
    $stmt->execute([':project_id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'The requested research project was not found.';
        header('Location: index.php');
        exit;
    }

    // Check edit permission
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    if (!canEditProject($projectRole, $systemRole)) {
        $_SESSION['project_error'] = 'Access denied: You do not have permission to modify this research project.';
        header("Location: view.php?id={$projectId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Project Update Check Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'Database error verifying project permissions.';
    header('Location: index.php');
    exit;
}

// Sanitize inputs
$title                    = sanitize_input($_POST['title'] ?? '');
$description              = trim($_POST['description'] ?? '');
$researchArea             = sanitize_input($_POST['research_area'] ?? '');
$faculty                  = sanitize_input($_POST['faculty'] ?? '');
$department               = sanitize_input($_POST['department'] ?? '');
$objectives               = trim($_POST['objectives'] ?? '');
$methodology              = trim($_POST['methodology'] ?? '');
$dataType                 = sanitize_input($_POST['data_type'] ?? '');
$dataCollectionLocation   = sanitize_input($_POST['data_collection_location'] ?? '');
$startDate                = trim($_POST['start_date'] ?? '');
$completionDate           = trim($_POST['completion_date'] ?? '');
$fundingInformation       = sanitize_input($_POST['funding_information'] ?? '');
$ethicalApproval          = sanitize_input($_POST['ethical_approval_information'] ?? '');
$status                   = strtolower(trim($_POST['status'] ?? 'planning'));

// Retain old edit input
$_SESSION['old_edit_project_data'] = [
    'id'                           => $projectId,
    'project_code'                 => $project['project_code'],
    'title'                        => $title,
    'description'                  => $description,
    'research_area'                => $researchArea,
    'faculty'                      => $faculty,
    'department'                   => $department,
    'objectives'                   => $objectives,
    'methodology'                  => $methodology,
    'data_type'                    => $dataType,
    'data_collection_location'     => $dataCollectionLocation,
    'start_date'                   => $startDate,
    'completion_date'              => $completionDate,
    'funding_information'          => $fundingInformation,
    'ethical_approval_information' => $ethicalApproval,
    'status'                       => $status
];

$errors = [];

// Validation: Required fields
if (empty($title)) {
    $errors[] = 'Project title is required.';
} elseif (strlen($title) > 255) {
    $errors[] = 'Project title cannot exceed 255 characters.';
}

if (empty($researchArea)) {
    $errors[] = 'Research area / domain is required.';
}

if (empty($faculty)) {
    $errors[] = 'Faculty / school is required.';
}

if (empty($department)) {
    $errors[] = 'Department is required.';
}

if (empty($objectives)) {
    $errors[] = 'Research objectives are required.';
}

if (empty($dataType)) {
    $errors[] = 'Primary data type is required.';
}

if (empty($dataCollectionLocation)) {
    $errors[] = 'Data collection location is required.';
}

// Validation: Status enum
$allowedStatuses = ['planning', 'active', 'completed', 'archived', 'cancelled'];
if (!in_array($status, $allowedStatuses, true)) {
    $errors[] = 'Invalid project lifecycle status.';
}

// Validation: Dates
if (empty($startDate)) {
    $errors[] = 'Start date is required.';
} else {
    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
    if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDate) {
        $errors[] = 'Start date must be a valid date format (YYYY-MM-DD).';
    }
}

if (!empty($completionDate)) {
    $completionDateObj = DateTime::createFromFormat('Y-m-d', $completionDate);
    if (!$completionDateObj || $completionDateObj->format('Y-m-d') !== $completionDate) {
        $errors[] = 'Completion date must be a valid date format (YYYY-MM-DD).';
    } elseif (!empty($startDate) && $completionDate < $startDate) {
        $errors[] = 'Completion date cannot be earlier than the start date.';
    }
}

// Redirect back if validation fails
if (!empty($errors)) {
    $_SESSION['edit_project_errors'] = $errors;
    header("Location: edit.php?id={$projectId}");
    exit;
}

// Execute update
try {
    $updateSql = "
        UPDATE research_projects SET
            title                        = :title,
            description                  = :description,
            research_area                = :research_area,
            faculty                      = :faculty,
            department                   = :department,
            objectives                   = :objectives,
            funding_information          = :funding_information,
            start_date                   = :start_date,
            completion_date              = :completion_date,
            methodology                  = :methodology,
            ethical_approval_information = :ethical_approval_information,
            data_type                    = :data_type,
            data_collection_location     = :data_collection_location,
            status                       = :status
        WHERE id = :id
    ";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':id'                           => $projectId,
        ':title'                        => $title,
        ':description'                  => !empty($description) ? $description : null,
        ':research_area'                => $researchArea,
        ':faculty'                      => $faculty,
        ':department'                   => $department,
        ':objectives'                   => $objectives,
        ':funding_information'          => !empty($fundingInformation) ? $fundingInformation : null,
        ':start_date'                   => $startDate,
        ':completion_date'              => !empty($completionDate) ? $completionDate : null,
        ':methodology'                  => !empty($methodology) ? $methodology : null,
        ':ethical_approval_information' => !empty($ethicalApproval) ? $ethicalApproval : null,
        ':data_type'                    => $dataType,
        ':data_collection_location'     => $dataCollectionLocation,
        ':status'                       => $status
    ]);

    // Record audit log
    log_audit(
        $pdo,
        'project_updated',
        'research_project',
        $projectId,
        "Updated research project '{$title}' ({$project['project_code']})"
    );

    // Clear old edit session data
    unset($_SESSION['old_edit_project_data']);
    unset($_SESSION['edit_project_errors']);

    $_SESSION['project_success'] = "Research project '{$title}' was successfully updated.";
    header("Location: view.php?id={$projectId}");
    exit;

} catch (PDOException $e) {
    error_log("Project Update Exception: " . $e->getMessage());
    $_SESSION['edit_project_errors'] = ['A database error occurred while updating the project. Please try again.'];
    header("Location: edit.php?id={$projectId}");
    exit;
}
