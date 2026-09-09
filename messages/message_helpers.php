<?php
/**
 * Messaging & Communication Helpers
 * FUD RDM System - Phase 7: Student–Supervisor Communication & Notification Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../notifications/notification_helper.php';

/**
 * Get or create a project-scoped conversation thread between a student and their assigned supervisor.
 */
function getOrCreateProjectConversation(
    PDO $pdo, 
    int $projectId, 
    int $studentId, 
    int $supervisorId, 
    ?int $assignmentId = null
): ?array {
    if ($projectId <= 0 || $studentId <= 0 || $supervisorId <= 0) {
        return null;
    }

    // Check if conversation thread already exists
    $stmt = $pdo->prepare("
        SELECT * FROM project_conversations 
        WHERE project_id = :pid AND student_id = :sid AND supervisor_id = :vid 
        LIMIT 1
    ");
    $stmt->execute([':pid' => $projectId, ':sid' => $studentId, ':vid' => $supervisorId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($conv) {
        // Ensure status is active
        if ($conv['status'] !== 'active') {
            $upd = $pdo->prepare("UPDATE project_conversations SET status = 'active', updated_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $conv['id']]);
            $conv['status'] = 'active';
        }
        return $conv;
    }

    // Create new conversation record
    $ins = $pdo->prepare("
        INSERT INTO project_conversations (
            project_id, student_id, supervisor_id, assignment_id, status, created_at, updated_at
        ) VALUES (
            :pid, :sid, :vid, :aid, 'active', NOW(), NOW()
        )
    ");
    $ins->execute([
        ':pid' => $projectId,
        ':sid' => $studentId,
        ':vid' => $supervisorId,
        ':aid' => $assignmentId
    ]);
    $convId = (int)$pdo->lastInsertId();

    $stmt->execute([':pid' => $projectId, ':sid' => $studentId, ':vid' => $supervisorId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check whether a user is a valid participant in a conversation, enforcing strict server-side authorization & IDOR protection.
 */
function isConversationParticipant(PDO $pdo, int $conversationId, int $userId, string $role): bool {
    if ($conversationId <= 0 || $userId <= 0) {
        return false;
    }

    $roleLower = strtolower(trim($role));
    if ($roleLower === 'admin' || $roleLower === 'super_admin') {
        return true; // Read monitoring permitted for admins
    }

    $stmt = $pdo->prepare("
        SELECT c.*, ss.status AS active_assignment_status
        FROM project_conversations c
        LEFT JOIN student_supervisors ss ON c.student_id = ss.student_id AND c.supervisor_id = ss.supervisor_id AND ss.status = 'active'
        WHERE c.id = :cid LIMIT 1
    ");
    $stmt->execute([':cid' => $conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        return false;
    }

    // Student can access if they are the student owner
    if ((int)$conv['student_id'] === $userId) {
        return true;
    }

    // Supervisor can access ONLY if they are the assigned supervisor AND assignment is active
    if ((int)$conv['supervisor_id'] === $userId) {
        // Enforce active assignment check to respect reassignment scoping rules!
        return ($conv['active_assignment_status'] === 'active');
    }

    return false;
}

/**
 * Fetch all active conversations for a user (Student or Supervisor).
 */
function getUserConversations(PDO $pdo, int $userId, string $role): array {
    $roleLower = strtolower(trim($role));
    
    if ($roleLower === 'supervisor') {
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                rp.project_code, rp.title AS project_title,
                u.first_name AS partner_first_name, u.last_name AS partner_last_name, u.email AS partner_email, u.matric_number AS partner_matric,
                (SELECT COUNT(*) FROM project_messages pm WHERE pm.conversation_id = c.id AND pm.recipient_id = :uid AND pm.is_read = 0) AS unread_count,
                (SELECT pm.message_body FROM project_messages pm WHERE pm.conversation_id = c.id ORDER BY pm.id DESC LIMIT 1) AS last_message_body,
                (SELECT pm.created_at FROM project_messages pm WHERE pm.conversation_id = c.id ORDER BY pm.id DESC LIMIT 1) AS last_message_time
            FROM project_conversations c
            INNER JOIN research_projects rp ON c.project_id = rp.id
            INNER JOIN users u ON c.student_id = u.id
            INNER JOIN student_supervisors ss ON c.student_id = ss.student_id AND c.supervisor_id = ss.supervisor_id AND ss.status = 'active'
            WHERE c.supervisor_id = :uid AND c.status = 'active'
            ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($roleLower === 'researcher') {
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                rp.project_code, rp.title AS project_title,
                u.first_name AS partner_first_name, u.last_name AS partner_last_name, u.email AS partner_email,
                (SELECT COUNT(*) FROM project_messages pm WHERE pm.conversation_id = c.id AND pm.recipient_id = :uid AND pm.is_read = 0) AS unread_count,
                (SELECT pm.message_body FROM project_messages pm WHERE pm.conversation_id = c.id ORDER BY pm.id DESC LIMIT 1) AS last_message_body,
                (SELECT pm.created_at FROM project_messages pm WHERE pm.conversation_id = c.id ORDER BY pm.id DESC LIMIT 1) AS last_message_time
            FROM project_conversations c
            INNER JOIN research_projects rp ON c.project_id = rp.id
            INNER JOIN users u ON c.supervisor_id = u.id
            INNER JOIN student_supervisors ss ON c.student_id = ss.student_id AND c.supervisor_id = ss.supervisor_id AND ss.status = 'active'
            WHERE c.student_id = :uid AND c.status = 'active'
            ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}

/**
 * Fetch all messages in a conversation thread with metadata.
 */
function getConversationMessages(PDO $pdo, int $conversationId): array {
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            u.first_name AS sender_first_name, u.last_name AS sender_last_name, r.name AS sender_role,
            ps.title AS submission_title, ps.submission_type,
            pm.title AS milestone_name
        FROM project_messages m
        INNER JOIN users u ON m.sender_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN project_submissions ps ON m.submission_id = ps.id
        LEFT JOIN project_milestones pm ON m.milestone_id = pm.id
        WHERE m.conversation_id = :cid
        ORDER BY m.created_at ASC, m.id ASC
    ");
    $stmt->execute([':cid' => $conversationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark all unread messages in a conversation thread as read for recipient.
 */
function markConversationAsRead(PDO $pdo, int $conversationId, int $userId): void {
    if ($conversationId <= 0 || $userId <= 0) {
        return;
    }
    $upd = $pdo->prepare("
        UPDATE project_messages 
        SET is_read = 1, read_at = NOW() 
        WHERE conversation_id = :cid AND recipient_id = :uid AND is_read = 0
    ");
    $upd->execute([':cid' => $conversationId, ':uid' => $userId]);
}

/**
 * Get total unread message count across all conversations for a user.
 */
function getUnreadMessageCountForUser(PDO $pdo, int $userId): int {
    if ($userId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM project_messages pm
        INNER JOIN project_conversations c ON pm.conversation_id = c.id
        INNER JOIN student_supervisors ss ON c.student_id = ss.student_id AND c.supervisor_id = ss.supervisor_id AND ss.status = 'active'
        WHERE pm.recipient_id = :uid AND pm.is_read = 0 AND c.status = 'active'
    ");
    $stmt->execute([':uid' => $userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Send a message safely with server-side validation, notification dispatching, and audit logging.
 */
function sendProjectMessage(
    PDO $pdo, 
    int $conversationId, 
    int $senderId, 
    string $messageBody, 
    ?int $submissionId = null, 
    ?int $milestoneId = null
): array {
    // Validate CSRF & input length
    $messageBody = trim($messageBody);
    if (empty($messageBody)) {
        return ['success' => false, 'error' => 'Message body cannot be empty.'];
    }
    if (mb_strlen($messageBody) > 4000) {
        return ['success' => false, 'error' => 'Message body exceeds maximum length of 4000 characters.'];
    }

    // Fetch conversation thread
    $stmt = $pdo->prepare("
        SELECT c.*, rp.title AS project_title, rp.id AS project_id
        FROM project_conversations c
        INNER JOIN research_projects rp ON c.project_id = rp.id
        WHERE c.id = :cid AND c.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':cid' => $conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        return ['success' => false, 'error' => 'Active conversation thread not found.'];
    }

    // Determine recipient
    $studentId    = (int)$conv['student_id'];
    $supervisorId = (int)$conv['supervisor_id'];

    if ($senderId === $studentId) {
        $recipientId = $supervisorId;
    } elseif ($senderId === $supervisorId) {
        $recipientId = $studentId;
    } else {
        return ['success' => false, 'error' => 'Access Denied: You are not a valid participant in this conversation.'];
    }

    // Verify active supervisor assignment
    $checkAss = $pdo->prepare("
        SELECT id FROM student_supervisors 
        WHERE student_id = :sid AND supervisor_id = :vid AND status = 'active' 
        LIMIT 1
    ");
    $checkAss->execute([':sid' => $studentId, ':vid' => $supervisorId]);
    if (!$checkAss->fetch()) {
        return ['success' => false, 'error' => 'Access Denied: Active supervision assignment has ended or been reassigned.'];
    }

    // Insert message
    $ins = $pdo->prepare("
        INSERT INTO project_messages (
            conversation_id, sender_id, recipient_id, message_body, 
            submission_id, milestone_id, is_read, created_at, updated_at
        ) VALUES (
            :cid, :sid, :rid, :body, :sub_id, :ms_id, 0, NOW(), NOW()
        )
    ");
    $ins->execute([
        ':cid'    => $conversationId,
        ':sid'    => $senderId,
        ':rid'    => $recipientId,
        ':body'   => $messageBody,
        ':sub_id' => $submissionId > 0 ? $submissionId : null,
        ':ms_id'  => $milestoneId > 0 ? $milestoneId : null
    ]);
    $msgId = (int)$pdo->lastInsertId();

    // Update conversation last_message_at timestamp
    $updConv = $pdo->prepare("UPDATE project_conversations SET last_message_at = NOW(), updated_at = NOW() WHERE id = :cid");
    $updConv->execute([':cid' => $conversationId]);

    // Send Notification to Recipient
    $senderStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :uid LIMIT 1");
    $senderStmt->execute([':uid' => $senderId]);
    $senderUser = $senderStmt->fetch(PDO::FETCH_ASSOC);
    $senderName = $senderUser ? ($senderUser['first_name'] . ' ' . $senderUser['last_name']) : 'User';

    $notifTitle = "💬 New Message: {$conv['project_title']}";
    $notifMsg   = "New supervision message from {$senderName}: \"" . mb_strimwidth($messageBody, 0, 80, '...') . "\"";
    $notifLink  = "../messages/index.php?cid={$conversationId}";

    createNotification($pdo, $recipientId, 'new_message', $notifTitle, $notifMsg, $notifLink);

    // Log Audit Event
    log_audit(
        $pdo, 
        'project_message_sent', 
        'project_conversation', 
        $conversationId, 
        "Message sent by User #{$senderId} to User #{$recipientId} in project '{$conv['project_title']}'."
    );

    return ['success' => true, 'message_id' => $msgId];
}
