<?php
/**
 * Approve Dataset Access Request Controller
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

$requestId = (int)($_POST['request_id'] ?? 0);
$reviewerComment = trim($_POST['reviewer_comment'] ?? '');

if ($requestId <= 0) {
    $_SESSION['access_error'] = 'Invalid access request identifier.';
    header('Location: index.php');
    exit;
}

// Validate CSRF
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['review_errors'] = ['Security validation failed (invalid CSRF token).'];
    header("Location: review.php?id={$requestId}");
    exit;
}

try {
    // Database transaction
    $pdo->beginTransaction();

    // 1. Fetch and lock request
    $stmt = $pdo->prepare("
        SELECT 
            ar.*,
            d.title AS dataset_title,
            d.owner_id AS dataset_owner_id,
            d.project_id,
            req.first_name AS requester_first_name,
            req.last_name AS requester_last_name
        FROM access_requests ar
        INNER JOIN datasets d ON ar.dataset_id = d.id
        INNER JOIN users req ON ar.requester_id = req.id
        WHERE ar.id = :id
        FOR UPDATE
    ");
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        $_SESSION['access_error'] = 'The access request no longer exists.';
        header('Location: index.php');
        exit;
    }

    $datasetId = (int)$request['dataset_id'];
    $projectId = (int)$request['project_id'];
    $datasetOwnerId = (int)$request['dataset_owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // 2. Verify reviewer authorization
    if (!canManageAccessRequests($projectRole, $systemRole, $datasetOwnerId, $userId)) {
        $pdo->rollBack();
        $_SESSION['access_error'] = 'Access denied: You do not have permission to approve access requests for this dataset.';
        header('Location: index.php');
        exit;
    }

    // 3. Concurrency check: Ensure status is still pending
    if ($request['status'] !== 'pending') {
        $pdo->rollBack();
        $_SESSION['access_error'] = 'This request has already been reviewed (Status: ' . ucfirst($request['status']) . ').';
        header("Location: view.php?id={$requestId}");
        exit;
    }

    // 4. Update request status to approved
    $upStmt = $pdo->prepare("
        UPDATE access_requests SET
            status           = 'approved',
            reviewed_by      = :reviewer_id,
            reviewed_at      = CURRENT_TIMESTAMP,
            reviewer_comment = :comment,
            updated_at       = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upStmt->execute([
        ':reviewer_id' => $userId,
        ':comment'     => !empty($reviewerComment) ? $reviewerComment : null,
        ':id'          => $requestId
    ]);

    // 5. Record audit log
    log_audit(
        $pdo,
        'access_request_approved',
        'access_requests',
        $requestId,
        "Approved access request #{$requestId} for researcher '{$request['requester_first_name']} {$request['requester_last_name']}' (#{$request['requester_id']}) on dataset '{$request['dataset_title']}' (#{$datasetId})"
    );

    // Notify requester of access approval
    require_once __DIR__ . '/../notifications/notification_helper.php';
    createNotification(
        $pdo,
        (int)$request['requester_id'],
        'access_request_approved',
        'Dataset Access Approved',
        "Your access request for restricted dataset '{$request['dataset_title']}' has been approved.",
        'dataset',
        $datasetId
    );

    $pdo->commit();

    $_SESSION['access_success'] = 'Access request approved. The researcher is now authorized to download dataset files.';
    header("Location: view.php?id={$requestId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Access Request Approve Error: " . $e->getMessage());
    $_SESSION['review_errors'] = ['A system error occurred while approving the access request. Please try again.'];
    header("Location: review.php?id={$requestId}");
    exit;
}
