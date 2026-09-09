<?php
/**
 * Student–Supervisor Supervision Chat & Communication Workspace
 * FUD RDM System - Phase 7: Student–Supervisor Communication & Notification Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/message_helpers.php';

requireAuth();

$user       = currentUser();
$userId     = (int)$user['id'];
$userRole   = strtolower($user['role'] ?? '');
$isSuper    = isSuperAdmin();

// Handle direct launch via GET params (e.g. project_id, student_id, supervisor_id)
$launchProjId = (int)($_GET['project_id'] ?? 0);
$launchStudId = (int)($_GET['student_id'] ?? 0);

if (($launchProjId > 0 || $launchStudId > 0) && ($userRole === 'supervisor' || $userRole === 'researcher')) {
    if ($userRole === 'researcher') {
        $studentId = $userId;
        if ($launchProjId <= 0) {
            $pStmt = $pdo->prepare("SELECT id FROM research_projects WHERE owner_id = :sid ORDER BY id DESC LIMIT 1");
            $pStmt->execute([':sid' => $studentId]);
            $launchProjId = (int)$pStmt->fetchColumn();
        }
        if ($launchProjId > 0) {
            // Fetch active supervisor
            $supStmt = $pdo->prepare("SELECT supervisor_id, id FROM student_supervisors WHERE student_id = :sid AND status = 'active' LIMIT 1");
            $supStmt->execute([':sid' => $studentId]);
            $ass = $supStmt->fetch(PDO::FETCH_ASSOC);
            if ($ass) {
                $conv = getOrCreateProjectConversation($pdo, $launchProjId, $studentId, (int)$ass['supervisor_id'], (int)$ass['id']);
                if ($conv) {
                    header("Location: index.php?cid={$conv['id']}");
                    exit;
                }
            }
        }
    } elseif ($userRole === 'supervisor' && $launchStudId > 0) {
        $supervisorId = $userId;
        if ($launchProjId <= 0) {
            $pStmt = $pdo->prepare("SELECT id FROM research_projects WHERE owner_id = :sid ORDER BY id DESC LIMIT 1");
            $pStmt->execute([':sid' => $launchStudId]);
            $launchProjId = (int)$pStmt->fetchColumn();
        }
        if ($launchProjId > 0) {
            $supStmt = $pdo->prepare("SELECT id FROM student_supervisors WHERE student_id = :sid AND supervisor_id = :vid AND status = 'active' LIMIT 1");
            $supStmt->execute([':sid' => $launchStudId, ':vid' => $supervisorId]);
            $ass = $supStmt->fetch(PDO::FETCH_ASSOC);
            if ($ass) {
                $conv = getOrCreateProjectConversation($pdo, $launchProjId, $launchStudId, $supervisorId, (int)$ass['id']);
                if ($conv) {
                    header("Location: index.php?cid={$conv['id']}");
                    exit;
                }
            }
        }
    }
}


// Fetch all conversations for current user
$conversations = getUserConversations($pdo, $userId, $userRole);

// Determine active conversation
$activeConvId = (int)($_GET['cid'] ?? 0);
if ($activeConvId <= 0 && !empty($conversations)) {
    $activeConvId = (int)$conversations[0]['id'];
}

$activeConv = null;
$messages = [];
$projectSubmissions = [];
$projectMilestones  = [];

if ($activeConvId > 0) {
    // Security & IDOR Authorization check
    if (!isConversationParticipant($pdo, $activeConvId, $userId, $userRole)) {
        http_response_code(403);
        $_SESSION['access_error'] = 'Access Denied: You are not authorized to view this supervision conversation thread.';
        header('Location: index.php');
        exit;
    }

    // Fetch conversation details
    $cStmt = $pdo->prepare("
        SELECT 
            c.*,
            rp.title AS project_title, rp.project_code, rp.id AS project_id,
            st.first_name AS student_first, st.last_name AS student_last, st.email AS student_email, st.matric_number AS student_matric,
            sp.first_name AS sup_first, sp.last_name AS sup_last, sp.email AS sup_email
        FROM project_conversations c
        INNER JOIN research_projects rp ON c.project_id = rp.id
        INNER JOIN users st ON c.student_id = st.id
        INNER JOIN users sp ON c.supervisor_id = sp.id
        WHERE c.id = :cid LIMIT 1
    ");
    $cStmt->execute([':cid' => $activeConvId]);
    $activeConv = $cStmt->fetch(PDO::FETCH_ASSOC);

    if ($activeConv) {
        // Mark unread messages in active thread as read
        markConversationAsRead($pdo, $activeConvId, $userId);

        // Fetch messages feed
        $messages = getConversationMessages($pdo, $activeConvId);

        // Fetch project submissions and milestones for composer dropdown selectors
        $subStmt = $pdo->prepare("
            SELECT id, title, submission_type,
                   (SELECT COALESCE(MAX(version_number), 1) FROM submission_versions WHERE submission_id = project_submissions.id) AS current_version
            FROM project_submissions 
            WHERE project_id = :pid 
            ORDER BY created_at DESC
        ");
        $subStmt->execute([':pid' => $activeConv['project_id']]);
        $projectSubmissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        $msStmt = $pdo->prepare("
            SELECT id, title AS milestone_name, status 
            FROM project_milestones 
            WHERE project_id = :pid 
            ORDER BY milestone_order ASC
        ");
        $msStmt->execute([':pid' => $activeConv['project_id']]);
        $projectMilestones = $msStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
$flashSuccess     = $_SESSION['flash_success'] ?? null;
$flashError       = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervision Messages & Communication — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .chat-container {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 1.25rem;
            height: calc(100vh - 160px);
            min-height: 580px;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 850px) {
            .chat-container {
                grid-template-columns: 1fr;
                height: auto;
            }
        }
        .chat-threads-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .threads-header {
            padding: 1rem 1.25rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .threads-list {
            overflow-y: auto;
            flex-grow: 1;
        }
        .thread-item {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            text-decoration: none;
            color: inherit;
            display: block;
            transition: background 0.15s ease;
        }
        .thread-item:hover, .thread-item.active {
            background: #eff6ff;
        }
        .thread-item.active {
            border-left: 4px solid var(--accent-color);
        }
        .partner-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--primary-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .proj-tag {
            font-size: 0.75rem;
            color: var(--accent-color);
            font-family: monospace;
            font-weight: 700;
        }
        .last-msg-snippet {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-feed-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        .chat-header {
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .chat-feed {
            flex-grow: 1;
            padding: 1.5rem;
            overflow-y: auto;
            background: #fdfdfd;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .msg-bubble-wrap {
            display: flex;
            flex-direction: column;
            max-width: 75%;
        }
        .msg-bubble-wrap.me {
            align-self: flex-end;
            align-items: flex-end;
        }
        .msg-bubble-wrap.other {
            align-self: flex-start;
            align-items: flex-start;
        }
        .msg-sender-lbl {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }
        .msg-bubble {
            padding: 0.85rem 1.1rem;
            border-radius: 12px;
            font-size: 0.9rem;
            line-height: 1.5;
            position: relative;
            box-shadow: var(--shadow-sm);
            white-space: pre-wrap;
        }
        .msg-bubble-wrap.me .msg-bubble {
            background: var(--primary-color);
            color: #ffffff;
            border-bottom-right-radius: 2px;
        }
        .msg-bubble-wrap.other .msg-bubble {
            background: #f1f5f9;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 2px;
        }
        .msg-context-tag {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            margin-bottom: 0.4rem;
        }
        .msg-bubble-wrap.me .msg-context-tag {
            background: rgba(255,255,255,0.2);
            color: #ffffff;
        }
        .msg-bubble-wrap.other .msg-context-tag {
            background: #e2e8f0;
            color: #334155;
        }
        .msg-meta {
            font-size: 0.7rem;
            margin-top: 0.25rem;
            color: var(--text-muted);
        }
        .chat-composer {
            padding: 1rem 1.5rem;
            background: #ffffff;
            border-top: 1px solid var(--border-color);
        }
        .composer-options {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.6rem;
            flex-wrap: wrap;
        }
        .composer-select {
            padding: 0.35rem 0.6rem;
            font-size: 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: #f8fafc;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="../index.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="../notifications/index.php" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; margin-right: 0.75rem; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);" title="Notifications">
                <span>🔔</span>
                <?php if ($notifUnreadCount > 0): ?>
                    <span style="background: #059669; color: #ffffff; font-size: 0.75rem; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                        <?= $notifUnreadCount; ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                </div>
                <span class="role-badge role-badge-<?= e($userRole); ?>"><?= e(ucfirst($userRole)); ?></span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Flash Notifications -->
        <?php if ($flashSuccess): ?>
            <div style="background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; font-weight: 600;">
                ✓ <?= e($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1rem; font-weight: 600;">
                ⚠ <?= e($flashError); ?>
            </div>
        <?php endif; ?>

        <div class="chat-container">

            <!-- Left Panel: Conversations Threads List -->
            <div class="chat-threads-panel">
                <div class="threads-header">
                    <span>💬 Supervision Threads</span>
                    <span style="background: var(--accent-color); color: #fff; font-size: 0.75rem; padding: 0.15rem 0.5rem; border-radius: 9999px;">
                        <?= count($conversations); ?>
                    </span>
                </div>
                <div class="threads-list">
                    <?php if (empty($conversations)): ?>
                        <div style="padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                            No active supervision conversations.
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $c): 
                            $isActive = ((int)$c['id'] === $activeConvId);
                            $partnerName = $c['partner_first_name'] . ' ' . $c['partner_last_name'];
                        ?>
                            <a href="index.php?cid=<?= (int)$c['id']; ?>" class="thread-item <?= $isActive ? 'active' : ''; ?>">
                                <div class="partner-name">
                                    <span><?= e($partnerName); ?></span>
                                    <span class="proj-tag"><?= e($c['project_code']); ?></span>
                                </div>
                                <div style="font-size: 0.8rem; font-weight: 600; color: var(--primary-color); margin-top: 0.15rem;">
                                    <?= e($c['project_title']); ?>
                                </div>
                                <div class="last-msg-snippet">
                                    <?= $c['last_message_body'] ? e($c['last_message_body']) : '<em>No messages yet</em>'; ?>
                                </div>
                                <?php if ((int)$c['unread_count'] > 0): ?>
                                    <div style="margin-top: 0.35rem;">
                                        <span style="background: #059669; color: #fff; font-size: 0.7rem; font-weight: 700; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                                            <?= (int)$c['unread_count']; ?> new
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Panel: Active Message Feed & Composer -->
            <div class="chat-feed-panel">
                <?php if (!$activeConv): ?>
                    <div style="padding: 4rem; text-align: center; color: var(--text-muted); flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                        <div style="font-size: 3rem; margin-bottom: 0.5rem;">💬</div>
                        <h3 style="font-size: 1.2rem; color: var(--primary-color); font-weight: 700;">No Conversation Selected</h3>
                        <p style="font-size: 0.9rem; margin-top: 0.25rem;">Select a supervision thread from the left panel to begin messaging.</p>
                    </div>
                <?php else: 
                    $isStudentUser = ($userRole === 'researcher');
                    $partnerTitle  = $isStudentUser 
                        ? 'Prof./Dr. ' . $activeConv['sup_first'] . ' ' . $activeConv['sup_last']
                        : $activeConv['student_first'] . ' ' . $activeConv['student_last'];
                    $partnerRoleLbl= $isStudentUser ? 'Assigned Supervisor' : 'Student Researcher';
                ?>
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <div>
                            <div style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color);">
                                💬 <?= e($partnerTitle); ?>
                                <span style="font-size: 0.75rem; font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 0.15rem 0.5rem; border-radius: 9999px; margin-left: 0.35rem;">
                                    <?= e($partnerRoleLbl); ?>
                                </span>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem;">
                                📂 Project: <strong><?= e($activeConv['project_title']); ?></strong> (<?= e($activeConv['project_code']); ?>)
                            </div>
                        </div>
                        <div>
                            <a href="../projects/view.php?id=<?= (int)$activeConv['project_id']; ?>" class="btn-sm" style="background: #f1f5f9; color: var(--primary-color); text-decoration: none; border: 1px solid var(--border-color); font-size: 0.8rem; font-weight: 600; padding: 0.35rem 0.75rem; border-radius: 4px;">
                                📂 Open Project &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Messages Feed -->
                    <div class="chat-feed">
                        <?php if (empty($messages)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.875rem;">
                                Start the supervision conversation thread regarding <strong><?= e($activeConv['project_title']); ?></strong>.
                            </div>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): 
                                $isMe = ((int)$msg['sender_id'] === $userId);
                                $senderLabel = $isMe ? 'You' : ($msg['sender_first_name'] . ' ' . $msg['sender_last_name']);
                            ?>
                                <div class="msg-bubble-wrap <?= $isMe ? 'me' : 'other'; ?>">
                                    <div class="msg-sender-lbl"><?= e($senderLabel); ?></div>
                                    <div class="msg-bubble">
                                        <?php if ($msg['submission_title']): ?>
                                            <div class="msg-context-tag">
                                                📄 <?= e($msg['submission_title']); ?> (<?= e(str_replace('_', ' ', strtoupper($msg['submission_type']))); ?>)
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($msg['milestone_name']): ?>
                                            <div class="msg-context-tag">
                                                🎯 Milestone: <?= e($msg['milestone_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div><?= e($msg['message_body']); ?></div>
                                    </div>
                                    <div class="msg-meta">
                                        <?= date('M d, Y H:i', strtotime($msg['created_at'])); ?>
                                        <?php if ($isMe): ?>
                                            &bull; <span style="font-weight: 700; color: <?= $msg['is_read'] ? '#059669' : '#94a3b8'; ?>;">
                                                <?= $msg['is_read'] ? '✓✓ Read' : '✓ Sent'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Message Composer Form -->
                    <div class="chat-composer">
                        <?php if ($isSuper): ?>
                            <div style="background: #fef3c7; color: #92400e; padding: 0.75rem; border-radius: 4px; font-size: 0.85rem; font-weight: 600; text-align: center;">
                                👁️ Super Admin is in read-only mode and cannot send messages.
                            </div>
                        <?php else: ?>
                            <form action="send_message.php" method="POST">
                                <?= csrfField(); ?>
                                <input type="hidden" name="conversation_id" value="<?= (int)$activeConvId; ?>">

                                <div class="composer-options">
                                    <?php if (!empty($projectSubmissions)): ?>
                                        <select name="submission_id" class="composer-select">
                                            <option value="">-- Link Submission Context (Optional) --</option>
                                            <?php foreach ($projectSubmissions as $subOption): ?>
                                                <option value="<?= (int)$subOption['id']; ?>">
                                                    📄 <?= e($subOption['title']); ?> (v<?= (int)$subOption['current_version']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if (!empty($projectMilestones)): ?>
                                        <select name="milestone_id" class="composer-select">
                                            <option value="">-- Link Milestone Context (Optional) --</option>
                                            <?php foreach ($projectMilestones as $msOption): ?>
                                                <option value="<?= (int)$msOption['id']; ?>">
                                                    🎯 <?= e($msOption['milestone_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; gap: 0.75rem; align-items: flex-end;">
                                    <textarea name="message_body" class="form-control" rows="2" placeholder="Type your supervision message to <?= e($partnerTitle); ?>..." required style="resize: vertical; flex-grow: 1; font-size: 0.9rem;"></textarea>
                                    <button type="submit" class="btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700; font-size: 0.9rem; white-space: nowrap;">
                                        Send Message ➔
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>
            </div>

        </div>

    </main>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
