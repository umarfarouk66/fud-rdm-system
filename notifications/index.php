<?php
/**
 * Notification Center
 * RDM Information System - Step 13
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/notification_helper.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$feedbackMessage = '';
$feedbackType    = 'success';

// Handle State-Changing POST Actions (Mark as Read / Mark All as Read)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $feedbackMessage = 'Invalid security token. Please try again.';
        $feedbackType    = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'mark_all_read') {
            $updated = markAllNotificationsAsRead($pdo, $userId);
            $feedbackMessage = "Marked {$updated} notification(s) as read.";
        } elseif ($action === 'mark_read') {
            $notifId = (int)($_POST['notification_id'] ?? 0);
            if ($notifId > 0 && markNotificationAsRead($pdo, $notifId, $userId)) {
                $feedbackMessage = 'Notification marked as read.';
            } else {
                $feedbackMessage = 'Unable to mark notification as read.';
                $feedbackType    = 'danger';
            }
        }
    }
}

// Filtering & Pagination
$filter = strtolower(trim($_GET['filter'] ?? 'all'));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$queryFilters = [];
if ($filter === 'unread') {
    $queryFilters['unread_only'] = true;
} elseif (in_array($filter, ['access', 'dmp', 'dataset', 'preservation', 'project'], true)) {
    // Whitelisted category mapping
    $typeMap = [
        'access'       => 'access_request_created',
        'dmp'          => 'dmp_submitted',
        'dataset'      => 'dataset_created',
        'preservation' => 'preservation_created',
        'project'      => 'project_member_added'
    ];
}

$totalNotifications = getUserNotificationCount($pdo, $userId, $queryFilters);
$notifications      = getUserNotifications($pdo, $userId, $queryFilters, $limit, $offset);
$unreadCount        = getUnreadNotificationCount($pdo, $userId);
$totalPages         = max(1, (int)ceil($totalNotifications / $limit));

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .notif-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .notif-header-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .notif-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
            flex-wrap: wrap;
        }
        .notif-tab {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-muted);
            background: #f8fafc;
            border: 1px solid var(--border-color);
            transition: all 0.15s ease;
        }
        .notif-tab.active {
            background: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .notif-tab:hover:not(.active) {
            background: #f1f5f9;
            color: var(--text-main);
        }
        .notif-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 0.85rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            gap: 1.25rem;
            align-items: flex-start;
            transition: border-color 0.15s ease, background 0.15s ease;
            position: relative;
        }
        .notif-card.unread {
            background: #f0fdf4;
            border-left: 4px solid #059669;
        }
        .notif-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
        .notif-content {
            flex-grow: 1;
        }
        .notif-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .notif-badge {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger  { background: #fee2e2; color: #991b1b; }
        .badge-info    { background: #e0f2fe; color: #075985; }
        .badge-primary { background: #e0e7ff; color: #3730a3; }
        .badge-secondary { background: #f1f5f9; color: #475569; }

        .notif-message {
            font-size: 0.9rem;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            line-height: 1.5;
        }
        .notif-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .btn-mark-read {
            background: none;
            border: none;
            color: var(--accent-color);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .btn-mark-read:hover {
            color: #1d4ed8;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="index.php" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; margin-right: 0.75rem; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                <span>🔔</span>
                <span>Notifications</span>
                <?php if ($unreadCount > 0): ?>
                    <span style="background: #059669; color: #ffffff; font-size: 0.75rem; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                        <?= $unreadCount; ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                </div>
                <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">
        <div class="notif-container">

            <!-- Breadcrumbs -->
            <div style="margin-bottom: 1rem;">
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    &larr; Back to Dashboard
                </a>
            </div>

            <!-- Feedback Alert -->
            <?php if (!empty($feedbackMessage)): ?>
                <div class="dash-alert dash-alert-<?= e($feedbackType); ?>" style="margin-bottom: 1.5rem;">
                    <span><?= e($feedbackMessage); ?></span>
                </div>
            <?php endif; ?>

            <!-- Header -->
            <div class="notif-header-box">
                <div>
                    <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                        🔔 Notification Center
                    </h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">
                        Track important workflow events, review updates, access decisions and preservation alerts.
                    </p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <form action="index.php" method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn-action" style="background: #ffffff; color: var(--primary-color); border: 1px solid var(--border-color); font-weight: 600; padding: 0.5rem 1rem; border-radius: var(--radius-sm); cursor: pointer;">
                            ✓ Mark All as Read (<?= $unreadCount; ?>)
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Filter Tabs -->
            <div class="notif-tabs">
                <a href="index.php?filter=all" class="notif-tab <?= ($filter === 'all') ? 'active' : ''; ?>">
                    All Notifications (<?= $totalNotifications; ?>)
                </a>
                <a href="index.php?filter=unread" class="notif-tab <?= ($filter === 'unread') ? 'active' : ''; ?>">
                    Unread (<?= $unreadCount; ?>)
                </a>
            </div>

            <!-- Notifications List -->
            <?php if (empty($notifications)): ?>
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 3rem 1.5rem; text-align: center;">
                    <div style="font-size: 3rem; margin-bottom: 0.75rem;">📭</div>
                    <h3 style="font-size: 1.2rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.35rem;">
                        No Notifications
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.9rem; max-width: 400px; margin: 0 auto;">
                        <?= ($filter === 'unread') ? 'You have caught up with all notifications.' : 'There are no notifications recorded in your feed.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($notifications as $notif): 
                        $meta = getNotificationTypeMeta($notif['type'] ?? '');
                        $link = resolveNotificationLink($notif['related_type'], $notif['related_id'], '../');
                        $isUnread = ((int)$notif['is_read'] === 0);
                    ?>
                        <div class="notif-card <?= $isUnread ? 'unread' : ''; ?>">
                            <div class="notif-icon-box">
                                <?= $meta['icon']; ?>
                            </div>
                            <div class="notif-content">
                                <div class="notif-title">
                                    <span><?= e($notif['title']); ?></span>
                                    <span class="notif-badge <?= e($meta['badge']); ?>">
                                        <?= e($meta['label']); ?>
                                    </span>
                                </div>
                                <div class="notif-message">
                                    <?= e($notif['message']); ?>
                                </div>
                                <div class="notif-footer">
                                    <div>
                                        <span>🕒 <?= date('M d, Y H:i', strtotime($notif['created_at'])); ?></span>
                                        <?php if (!$isUnread && !empty($notif['read_at'])): ?>
                                            <span style="margin-left: 0.5rem; color: #94a3b8;">&bull; Read <?= date('M d, H:i', strtotime($notif['read_at'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display: flex; gap: 1rem; align-items: center;">
                                        <?php if ($link): ?>
                                            <a href="<?= e($link); ?>" style="color: var(--accent-color); font-weight: 700; text-decoration: none; font-size: 0.85rem;">
                                                View Record &rarr;
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($isUnread): ?>
                                            <form action="index.php?filter=<?= e($filter); ?>&page=<?= $page; ?>" method="POST" style="margin: 0; display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?= (int)$notif['id']; ?>">
                                                <button type="submit" class="btn-mark-read">Mark as read</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 2rem;">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="index.php?filter=<?= e($filter); ?>&page=<?= $p; ?>" style="padding: 0.4rem 0.85rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-weight: 600; text-decoration: none; <?= ($p === $page) ? 'background: var(--primary-color); color: #ffffff;' : 'background: #ffffff; color: var(--text-main); border: 1px solid var(--border-color);'; ?>">
                                <?= $p; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

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
