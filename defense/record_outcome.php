<?php
/**
 * Record Defense Outcome Controller
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

requireRole(['admin']);
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];

$defenseId = (int)($_POST['defense_id'] ?? 0);
if ($defenseId <= 0) {
    $_SESSION['flash_error'] = 'Invalid defense identification parameter.';
    header('Location: ../admin/defense_management.php');
    exit;
}

$result = recordDefenseOutcome($pdo, $defenseId, $_POST, $userId);

if ($result['success']) {
    $_SESSION['flash_success'] = 'Defense examination outcome recorded successfully.';
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Failed to record defense outcome.';
}

$redirect = $_POST['redirect_url'] ?? '../admin/defense_management.php';
header("Location: {$redirect}");
exit;
