<?php
/**
 * Admin Supervision Messaging Monitoring Center
 * FUD RDM System - Phase 7: Student–Supervisor Communication & Notification Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../messages/message_helpers.php';

// Enforce admin role (super_admin allowed read-only)
requireRole('admin');

$user    = currentUser();
$userId  = (int)$user['id'];
$isSuper = isSuperAdmin();

// Filter parameters
$search     = trim($_GET['search'] ?? '');
$deptFilter = trim($_GET['department'] ?? '');

$query = "
    SELECT 
        c.*,
        rp.title AS project_title, rp.project_code,
        st.first_name AS student_first, st.last_name AS student_last, st.matric_number AS student_matric, st.department AS student_dept,
        sp.first_name AS sup_first, sp.last_name AS sup_last, sp.email AS sup_email,
        (SELECT COUNT(*) FROM project_messages pm WHERE pm.conversation_id = c.id) AS total_messages,
        (SELECT pm.created_at FROM project_messages pm WHERE pm.conversation_id = c.id ORDER BY pm.id DESC LIMIT 1) AS last_message_time
    FROM project_conversations c
    INNER JOIN research_projects rp ON c.project_id = rp.id
    INNER JOIN users st ON c.student_id = st.id
    INNER JOIN users sp ON c.supervisor_id = sp.id
    WHERE 1=1
";

$params = [];
if ($search !== '') {
    $query .= " AND (st.first_name LIKE :s OR st.last_name LIKE :s OR st.matric_number LIKE :s OR sp.first_name LIKE :s OR sp.last_name LIKE :s OR rp.title LIKE :s OR rp.project_code LIKE :s)";
    $params[':s'] = "%{$search}%";
}
if ($deptFilter !== '') {
    $query .= " AND st.department = :dept";
    $params[':dept'] = $deptFilter;
}

$query .= " ORDER BY COALESCE(c.last_message_at, c.created_at) DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$conversationsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Selected thread for inspection
$inspectConvId = (int)($_GET['inspect_id'] ?? 0);
$inspectConv   = null;
$inspectMsgs   = [];

if ($inspectConvId > 0) {
    $iStmt = $pdo->prepare("
        SELECT c.*, rp.title AS project_title, rp.project_code,
               st.first_name AS student_first, st.last_name AS student_last, st.matric_number AS student_matric,
               sp.first_name AS sup_first, sp.last_name AS sup_last
        FROM project_conversations c
        INNER JOIN research_projects rp ON c.project_id = rp.id
        INNER JOIN users st ON c.student_id = st.id
        INNER JOIN users sp ON c.supervisor_id = sp.id
        WHERE c.id = :cid LIMIT 1
    ");
    $iStmt->execute([':cid' => $inspectConvId]);
    $inspectConv = $iStmt->fetch(PDO::FETCH_ASSOC);

    if ($inspectConv) {
        $inspectMsgs = getConversationMessages($pdo, $inspectConvId);
    }
}

// Distinct departments list for filter
$deptStmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departmentsList = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervision Messaging Monitoring — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .layout-grid {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 992px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table th, .data-table td {
            padding: 0.75rem 0.85rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .msg-inspect-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .inspect-feed {
            max-height: 480px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding: 0.5rem 0;
        }
        .inspect-msg {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
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
                    <div class="user-affiliation"><?= isSuperAdmin() ? 'Institutional Monitor' : 'System Administrator'; ?></div>
                </div>
                <span class="role-badge role-badge-admin" style="<?= isSuperAdmin() ? 'background: #4338ca; color: #ffffff;' : ''; ?>">
                    <?= isSuperAdmin() ? 'Super Admin' : 'Admin'; ?>
                </span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
        </div>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                💬 Supervision Messaging Monitoring & Institutional Audit
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Institutional oversight of student-supervisor communication activity, message volume, timestamps and compliance history.
            </p>
        </div>

        <!-- Filter Form -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm);">
            <form method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 220px;">
                    <label class="form-label" style="font-size: 0.8rem;">Search Student / Supervisor / Project</label>
                    <input type="text" name="search" class="form-control" placeholder="Search name, matric or project..." value="<?= e($search); ?>">
                </div>

                <div style="min-width: 180px;">
                    <label class="form-label" style="font-size: 0.8rem;">Department Filter</label>
                    <select name="department" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentsList as $d): ?>
                            <option value="<?= e($d); ?>" <?= $deptFilter === $d ? 'selected' : ''; ?>><?= e($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn-primary" style="padding: 0.55rem 1.25rem; font-weight: 700;">Filter</button>
                    <a href="conversations.php" class="btn-logout" style="padding: 0.55rem 1rem; border: 1px solid var(--border-color); background: #fff; color: var(--text-main); text-decoration: none;">Reset</a>
                </div>
            </form>
        </div>

        <div class="layout-grid">
            <!-- Left Column: Conversations Master List -->
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                    📜 Supervision Threads (<?= count($conversationsList); ?>)
                </h3>

                <?php if (empty($conversationsList)): ?>
                    <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                        No supervision conversations found matching criteria.
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Supervisor</th>
                                    <th>Project</th>
                                    <th>Messages</th>
                                    <th>Last Activity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($conversationsList as $row): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--primary-color);">
                                                <?= e($row['student_first'] . ' ' . $row['student_last']); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">
                                                <?= e($row['student_matric']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong>Prof./Dr. <?= e($row['sup_first'] . ' ' . $row['sup_last']); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; font-size: 0.85rem; max-width: 200px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                                <?= e($row['project_title']); ?>
                                            </div>
                                            <span style="font-family: monospace; font-size: 0.75rem; color: var(--accent-color);"><?= e($row['project_code']); ?></span>
                                        </td>
                                        <td style="font-weight: 700; text-align: center;">
                                            <?= (int)$row['total_messages']; ?>
                                        </td>
                                        <td style="font-size: 0.8rem;">
                                            <?= $row['last_message_time'] ? date('M d, Y H:i', strtotime($row['last_message_time'])) : date('M d, Y', strtotime($row['created_at'])); ?>
                                        </td>
                                        <td>
                                            <a href="conversations.php?inspect_id=<?= (int)$row['id']; ?>" class="btn-sm" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                Inspect &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Read-Only Message Feed Inspector -->
            <div class="msg-inspect-box">
                <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem; border-bottom: 2px solid var(--accent-color); padding-bottom: 0.5rem;">
                    🔍 Conversation Feed Inspector
                </h3>

                <?php if (!$inspectConv): ?>
                    <div style="padding: 3rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                        Click <strong>Inspect &rarr;</strong> on any supervision thread to review its message history in read-only audit mode.
                    </div>
                <?php else: ?>
                    <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1rem;">
                        <div style="font-size: 0.9rem; font-weight: 700; color: var(--primary-color);">
                            <?= e($inspectConv['project_title']); ?> (<?= e($inspectConv['project_code']); ?>)
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                            <strong>Student:</strong> <?= e($inspectConv['student_first'] . ' ' . $inspectConv['student_last']); ?> (<?= e($inspectConv['student_matric']); ?>)<br>
                            <strong>Supervisor:</strong> Prof./Dr. <?= e($inspectConv['sup_first'] . ' ' . $inspectConv['sup_last']); ?>
                        </div>
                    </div>

                    <div class="inspect-feed">
                        <?php if (empty($inspectMsgs)): ?>
                            <div style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 1rem;">
                                No messages logged in this thread yet.
                            </div>
                        <?php else: ?>
                            <?php foreach ($inspectMsgs as $im): ?>
                                <div class="inspect-msg">
                                    <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 0.8rem; margin-bottom: 0.25rem;">
                                        <span><?= e($im['sender_first_name'] . ' ' . $im['sender_last_name']); ?> (<?= e(ucfirst($im['sender_role'] ?? 'user')); ?>)</span>
                                        <span style="color: var(--text-muted); font-weight: normal;"><?= date('M d, H:i', strtotime($im['created_at'])); ?></span>
                                    </div>
                                    <div style="color: var(--text-main); white-space: pre-wrap;"><?= e($im['message_body']); ?></div>
                                </div>
                            <?php endforeach; ?>
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
