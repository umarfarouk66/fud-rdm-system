<?php
/**
 * Librarian Repository Review & Publication Controller
 * FUD RDM System - Phase 9: Repository Integration & Final Project Publication
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/repository_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../librarian/dashboard.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireRole(['librarian', 'admin']);
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];

$datasetId = (int)($_POST['dataset_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');

if ($datasetId <= 0 || empty($action)) {
    $_SESSION['flash_error'] = 'Invalid repository review parameters.';
    header('Location: ../librarian/dashboard.php');
    exit;
}

$result = reviewRepositorySubmission($pdo, $datasetId, $action, $_POST, $userId);

if ($result['success']) {
    $_SESSION['flash_success'] = $result['message'] ?? 'Repository action executed successfully.';
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Failed to execute repository action.';
}

$redirect = $_POST['redirect_url'] ?? "../librarian/repository_review.php?dataset_id={$datasetId}";
header("Location: {$redirect}");
exit;
