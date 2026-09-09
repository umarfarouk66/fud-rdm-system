<?php
/**
 * Project Message Sending Controller
 * FUD RDM System - Phase 7: Student–Supervisor Communication & Notification Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/message_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireAuth();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];
$role   = $user['role'];

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$messageBody    = trim($_POST['message_body'] ?? '');
$submissionId   = (int)($_POST['submission_id'] ?? 0);
$milestoneId    = (int)($_POST['milestone_id'] ?? 0);

if ($conversationId <= 0 || empty($messageBody)) {
    $_SESSION['flash_error'] = 'Message body cannot be empty.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

// IDOR & Participant Authorization Check
if (!isConversationParticipant($pdo, $conversationId, $userId, $role)) {
    http_response_code(403);
    $_SESSION['flash_error'] = 'Access Denied: You are not authorized to send messages in this conversation thread.';
    header('Location: index.php');
    exit;
}

// Send Message
$result = sendProjectMessage($pdo, $conversationId, $userId, $messageBody, $submissionId, $milestoneId);

if ($result['success']) {
    $_SESSION['flash_success'] = 'Message sent successfully.';
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Failed to send message.';
}

header("Location: index.php?cid={$conversationId}");
exit;
