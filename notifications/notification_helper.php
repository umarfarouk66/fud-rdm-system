<?php
/**
 * Notifications Helper Functions
 * RDM Information System - Step 13
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

/**
 * Standard Notification Types
 */
define('NOTIFICATION_TYPES', [
    'access_request_created'   => ['label' => 'Access Request', 'icon' => '🔑', 'badge' => 'badge-warning'],
    'access_request_approved'  => ['label' => 'Access Approved', 'icon' => '✅', 'badge' => 'badge-success'],
    'access_request_rejected'  => ['label' => 'Access Declined', 'icon' => '❌', 'badge' => 'badge-danger'],
    'dmp_submitted'            => ['label' => 'DMP Submitted', 'icon' => '📋', 'badge' => 'badge-info'],
    'dmp_approved'             => ['label' => 'DMP Approved', 'icon' => '✅', 'badge' => 'badge-success'],
    'dmp_rejected'             => ['label' => 'DMP Revision', 'icon' => '⚠️', 'badge' => 'badge-warning'],
    'dataset_created'          => ['label' => 'Dataset Deposited', 'icon' => '📊', 'badge' => 'badge-primary'],
    'dataset_archived'         => ['label' => 'Dataset Archived', 'icon' => '📦', 'badge' => 'badge-secondary'],
    'preservation_created'     => ['label' => 'Dataset Preserved', 'icon' => '🛡️', 'badge' => 'badge-success'],
    'preservation_verified'    => ['label' => 'Integrity Verified', 'icon' => '🔒', 'badge' => 'badge-success'],
    'preservation_failed'      => ['label' => 'Integrity Warning', 'icon' => '🚨', 'badge' => 'badge-danger'],
    'project_member_added'     => ['label' => 'Project Member', 'icon' => '👥', 'badge' => 'badge-info'],
    'system_alert'             => ['label' => 'System Alert', 'icon' => '🔔', 'badge' => 'badge-secondary']
]);

/**
 * Create a new notification for a specific user with deduplication protection
 */
function createNotification(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $relatedType = null,
    ?int $relatedId = null,
    int $preventDuplicateWithinMinutes = 5
): ?int {
    try {
        // Prevent duplicate spam if requested
        if ($preventDuplicateWithinMinutes > 0) {
            $checkStmt = $pdo->prepare("
                SELECT id FROM notifications
                WHERE user_id = ? AND type = ? AND title = ? AND is_read = 0
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
                LIMIT 1
            ");
            $checkStmt->execute([$userId, $type, $title, $preventDuplicateWithinMinutes]);
            if ($checkStmt->fetchColumn()) {
                // Duplicate active unread notification found, skip insert
                return null;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_type, related_id, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $userId,
            $title,
            $message,
            $type,
            $relatedType,
            $relatedId
        ]);

        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return null;
    }
}

/**
 * Retrieve paginated notifications for a user
 */
function getUserNotifications(PDO $pdo, int $userId, array $filters = [], int $limit = 20, int $offset = 0): array {
    $where = ["user_id = :uid"];
    $params = [':uid' => $userId];

    if (!empty($filters['unread_only'])) {
        $where[] = "is_read = 0";
    }

    if (!empty($filters['type'])) {
        $where[] = "type = :type";
        $params[':type'] = $filters['type'];
    }

    $whereClause = implode(" AND ", $where);
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);

    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, title, message, type, related_type, related_id, is_read, read_at, created_at
            FROM notifications
            WHERE {$whereClause}
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch user notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Get total notification count for pagination
 */
function getUserNotificationCount(PDO $pdo, int $userId, array $filters = []): int {
    $where = ["user_id = :uid"];
    $params = [':uid' => $userId];

    if (!empty($filters['unread_only'])) {
        $where[] = "is_read = 0";
    }

    if (!empty($filters['type'])) {
        $where[] = "type = :type";
        $params[':type'] = $filters['type'];
    }

    $whereClause = implode(" AND ", $where);

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereClause}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to count notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get unread notification count for authenticated navbar badge
 */
function getUnreadNotificationCount(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to get unread notification count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a specific notification as read (strictly verifies user ownership)
 */
function markNotificationAsRead(PDO $pdo, int $notificationId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = CURRENT_TIMESTAMP
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Failed to mark notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for current user
 */
function markAllNotificationsAsRead(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = CURRENT_TIMESTAMP
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Failed to mark all notifications as read: " . $e->getMessage());
        return 0;
    }
}

/**
 * Resolve relative application route based on related_type and related_id
 */
function resolveNotificationLink(?string $relatedType, ?int $relatedId, string $basePath = '../'): ?string {
    if (empty($relatedType) || empty($relatedId)) {
        return null;
    }

    $id = (int)$relatedId;
    switch (strtolower(trim($relatedType))) {
        case 'dataset':
            return "{$basePath}datasets/view.php?id={$id}";
        case 'project':
            return "{$basePath}projects/view.php?id={$id}";
        case 'dmp':
            return "{$basePath}dmp/view.php?id={$id}";
        case 'access_request':
            return "{$basePath}access/view.php?id={$id}";
        case 'preservation':
            return "{$basePath}preservation/view.php?id={$id}";
        default:
            return null;
    }
}

/**
 * Get notification metadata (icon, label, badge)
 */
function getNotificationTypeMeta(string $type): array {
    $types = NOTIFICATION_TYPES;
    return $types[$type] ?? [
        'label' => 'Notification',
        'icon'  => '🔔',
        'badge' => 'badge-secondary'
    ];
}
