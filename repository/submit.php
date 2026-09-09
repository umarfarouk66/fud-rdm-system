<?php
/**
 * Student Repository Submission Controller
 * FUD RDM System - Phase 9: Repository Integration & Final Project Publication
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/repository_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../projects/index.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireRole(['researcher']);
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0) {
    $_SESSION['flash_error'] = 'Invalid research project identifier.';
    header('Location: ../projects/index.php');
    exit;
}

$result = submitProjectToRepository($pdo, $projectId, $_POST, $userId);

if ($result['success']) {
    $_SESSION['flash_success'] = 'Research project successfully submitted for Institutional Repository review & publication!';
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Failed to submit project to repository.';
}

header("Location: ../projects/view.php?id={$projectId}");
exit;
