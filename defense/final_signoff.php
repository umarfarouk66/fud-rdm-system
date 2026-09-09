<?php
/**
 * Final Institutional Project Completion Approval Controller
 * FUD RDM System - Phase 8: Final Project Approval + Defense/Viva Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/defense_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin/defense_management.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireRole(['admin', 'supervisor']);
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];

$projectId = (int)($_POST['project_id'] ?? 0);
$remarks   = trim($_POST['completion_remarks'] ?? '');

if ($projectId <= 0) {
    $_SESSION['flash_error'] = 'Invalid project identifier specified.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
    exit;
}

$result = grantFinalProjectApproval($pdo, $projectId, $userId, $remarks);

if ($result['success']) {
    $_SESSION['flash_success'] = 'Research project has been granted final institutional approval and marked COMPLETED!';
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Failed to grant final project completion approval.';
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
exit;
