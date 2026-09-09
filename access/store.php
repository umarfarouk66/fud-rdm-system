<?php
/**
 * Store Dataset Access Request Controller
 * RDM Information System - Step 9
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/access_helpers.php';

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
$reason = trim($_POST['reason'] ?? '');

if ($datasetId <= 0) {
    $_SESSION['access_error'] = 'Invalid dataset specified.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['request_access_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: create.php?dataset_id={$datasetId}");
    exit;
}

$_SESSION['old_request_data'] = ['reason' => $reason];

// Validate Reason
$errors = [];
if (empty($reason)) {
    $errors[] = 'Please provide a justification explaining your research purpose and data handling protocols.';
} elseif (strlen($reason) < 10) {
    $errors[] = 'Your research justification is too brief. Please provide at least 10 characters.';
} elseif (strlen($reason) > 5000) {
    $errors[] = 'Your research justification exceeds the 5,000 character limit.';
}

if (!empty($errors)) {
    $_SESSION['request_access_errors'] = $errors;
    header("Location: create.php?dataset_id={$datasetId}");
    exit;
}

try {
    // 1. Fetch dataset and verify rules
    $dStmt = $pdo->prepare("
        SELECT 
            d.id, d.title, d.access_level, d.owner_id, d.project_id, d.status
        FROM datasets d
        WHERE d.id = :id
        LIMIT 1
    ");
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['access_error'] = 'The requested dataset is no longer available.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $accessLevel = strtolower(trim($dataset['access_level']));
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Rule: Public dataset
    if ($accessLevel === 'public') {
        $_SESSION['access_error'] = 'This dataset is publicly accessible. An access request is not required.';
        header("Location: ../datasets/download.php?id={$datasetId}");
        exit;
    }

    // Rule: Private dataset
    if ($accessLevel === 'private' && $userId !== $ownerId && $projectRole === null && $systemRole !== 'admin') {
        $_SESSION['access_error'] = 'This dataset is private and access is limited to authorized project members.';
        header("Location: ../datasets/view.php?id={$datasetId}");
        exit;
    }

    // Rule: Already an owner or project member
    if ($userId === $ownerId || $projectRole !== null || $systemRole === 'admin') {
        $_SESSION['access_error'] = 'You already have access to this dataset as an authorized member.';
        header("Location: ../datasets/view.php?id={$datasetId}");
        exit;
    }

    // Rule: Duplicate active request check
    $latestReq = getUserLatestAccessRequest($pdo, $datasetId, $userId);
    if ($latestReq) {
        if ($latestReq['status'] === 'pending') {
            $_SESSION['access_error'] = 'You already have a pending access request for this dataset.';
            header("Location: view.php?id={$latestReq['id']}");
            exit;
        }
        if ($latestReq['status'] === 'approved') {
            $_SESSION['access_error'] = 'You already have approved access to this dataset.';
            header("Location: ../datasets/download.php?id={$datasetId}");
            exit;
        }
    }

    // Database transaction
    $pdo->beginTransaction();

    $insStmt = $pdo->prepare("
        INSERT INTO access_requests (
            dataset_id,
            requester_id,
            reason,
            status,
            created_at,
            updated_at
        ) VALUES (
            :dataset_id,
            :requester_id,
            :reason,
            'pending',
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
    ");

    $insStmt->execute([
        ':dataset_id'   => $datasetId,
        ':requester_id' => $userId,
        ':reason'       => $reason
    ]);

    $requestId = (int)$pdo->lastInsertId();

    // Record audit log
    log_audit(
        $pdo,
        'access_request_created',
        'access_requests',
        $requestId,
        "User '{$user['name']}' (#{$userId}) requested access to restricted dataset '{$dataset['title']}' (#{$datasetId})"
    );

    // Notify dataset owner of new access request
    require_once __DIR__ . '/../notifications/notification_helper.php';
    createNotification(
        $pdo,
        $ownerId,
        'access_request_created',
        'New Dataset Access Request',
        "User '{$user['name']}' requested access to your restricted dataset '{$dataset['title']}'.",
        'access_request',
        $requestId
    );

    $pdo->commit();

    unset($_SESSION['old_request_data']);
    unset($_SESSION['request_access_errors']);

    $_SESSION['access_success'] = 'Your access request has been submitted to the dataset custodian for review.';
    header("Location: view.php?id={$requestId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Access Request Store Error: " . $e->getMessage());
    $_SESSION['request_access_errors'] = ['A database error occurred while submitting your access request. Please try again.'];
    header("Location: create.php?dataset_id={$datasetId}");
    exit;
}
