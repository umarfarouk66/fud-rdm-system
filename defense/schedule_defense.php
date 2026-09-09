<?php
/**
 * Admin Defense & Viva Scheduling Controller
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

requireRole('admin');
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];
$projectId = (int)($_POST['project_id'] ?? 0);

if ($projectId <= 0) {
    $_SESSION['flash_error'] = 'Invalid project identifier for defense scheduling.';
    header('Location: ../admin/defense_management.php');
    exit;
}

$res = scheduleProjectDefense($pdo, $projectId, $_POST, $userId);

if ($res['success']) {
    $_SESSION['flash_success'] = 'Defense schedule saved and notifications dispatched.';
} else {
    $_SESSION['flash_error'] = $res['error'] ?? 'Failed to schedule defense.';
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
exit;
