<?php
/**
 * Store New Research Project Controller
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

// Validate CSRF token
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token). Please try again.';
    header('Location: create.php');
    exit;
}

$user = currentUser();
$ownerId = $user['id'];

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

// Retain old input for re-population
$_SESSION['old_project_data'] = [
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
    'ethical_approval_information' => $ethicalApproval
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
    $_SESSION['create_project_errors'] = $errors;
    header('Location: create.php');
    exit;
}

try {
    // Generate unique project code
    $projectCode = generateProjectCode($pdo);

    // Database transaction to create project, member and audit log atomically
    $pdo->beginTransaction();

    // 1. Insert research project
    $insertProjectSql = "
        INSERT INTO research_projects (
            project_code,
            owner_id,
            title,
            description,
            research_area,
            faculty,
            department,
            objectives,
            funding_information,
            start_date,
            completion_date,
            methodology,
            ethical_approval_information,
            data_type,
            data_collection_location,
            status
        ) VALUES (
            :project_code,
            :owner_id,
            :title,
            :description,
            :research_area,
            :faculty,
            :department,
            :objectives,
            :funding_information,
            :start_date,
            :completion_date,
            :methodology,
            :ethical_approval_information,
            :data_type,
            :data_collection_location,
            'planning'
        )
    ";

    $projectStmt = $pdo->prepare($insertProjectSql);
    $projectStmt->execute([
        ':project_code'                 => $projectCode,
        ':owner_id'                     => $ownerId,
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
        ':data_collection_location'     => $dataCollectionLocation
    ]);

    $projectId = (int)$pdo->lastInsertId();

    // 2. Insert owner into project_members
    $insertMemberSql = "
        INSERT INTO project_members (
            project_id,
            user_id,
            role,
            joined_at
        ) VALUES (
            :project_id,
            :user_id,
            'owner',
            CURRENT_TIMESTAMP
        )
    ";
    $memberStmt = $pdo->prepare($insertMemberSql);
    $memberStmt->execute([
        ':project_id' => $projectId,
        ':user_id'    => $ownerId
    ]);

    // 3. Record in audit_logs
    log_audit(
        $pdo,
        'project_created',
        'research_project',
        $projectId,
        "Created research project '{$title}' with code {$projectCode}"
    );

    // Commit transaction
    $pdo->commit();

    // Clear old form session data
    unset($_SESSION['old_project_data']);
    unset($_SESSION['create_project_errors']);

    $_SESSION['project_success'] = "Research project created successfully! Assigned Project Code: {$projectCode}";
    header("Location: view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Project Store Exception: " . $e->getMessage());
    $_SESSION['create_project_errors'] = ['A database error occurred while creating the project. Please try again.'];
    header('Location: create.php');
    exit;
}
