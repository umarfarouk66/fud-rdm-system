<?php
/**
 * System Administration Helpers
 * RDM Information System - Step 14
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

/**
 * Count the number of active users with the 'admin' role in the database
 */
function countActiveAdmins(PDO $pdo): int {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(u.id)
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'admin' AND u.status = 'active'
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Failed to count active admins: " . $e->getMessage());
        return 0;
    }
}

/**
 * Validate whether modifying a target user's role or status violates Self-Protection or Last-Admin Protection
 * 
 * @param PDO $pdo
 * @param int $targetUserId The user ID being modified
 * @param int $currentUserId The logged-in administrator user ID
 * @param string|int $newRole The proposed role name or role_id
 * @param string $newStatus The proposed status ('active', 'inactive', 'suspended')
 * @return array ['allowed' => bool, 'error' => string|null]
 */
function validateAdminModification(
    PDO $pdo,
    int $targetUserId,
    int $currentUserId,
    $newRole,
    string $newStatus
): array {
    // 1. Fetch current target user details
    $stmt = $pdo->prepare("
        SELECT u.id, u.role_id, u.status, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        return ['allowed' => false, 'error' => 'The target user account was not found.'];
    }

    $isTargetAdmin = (strtolower(trim($targetUser['role_name'])) === 'admin');
    $isTargetActive = (strtolower(trim($targetUser['status'])) === 'active');

    // Normalize proposed role name
    $proposedRoleName = '';
    if (is_numeric($newRole)) {
        $rStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $rStmt->execute([(int)$newRole]);
        $proposedRoleName = strtolower(trim((string)$rStmt->fetchColumn()));
    } else {
        $proposedRoleName = strtolower(trim((string)$newRole));
    }

    $proposedStatus = strtolower(trim($newStatus));
    $willBeAdmin = ($proposedRoleName === 'admin');
    $willBeActive = ($proposedStatus === 'active');

    // 2. Self-Protection Rules
    if ($targetUserId === $currentUserId) {
        if (!$willBeActive) {
            return ['allowed' => false, 'error' => 'Self-Protection: You cannot deactivate your own administrator account.'];
        }
        if (!$willBeAdmin) {
            return ['allowed' => false, 'error' => 'Self-Protection: You cannot remove your own administrator role.'];
        }
    }

    // 3. Last-Admin Protection Rules
    if ($isTargetAdmin && $isTargetActive) {
        // If operation results in target no longer being an active admin
        if (!$willBeAdmin || !$willBeActive) {
            $activeAdminCount = countActiveAdmins($pdo);
            if ($activeAdminCount <= 1) {
                return ['allowed' => false, 'error' => 'The system must have at least one active administrator.'];
            }
        }
    }

    return ['allowed' => true, 'error' => null];
}

/**
 * Path to safe system settings configuration file
 */
define('SYSTEM_SETTINGS_FILE', __DIR__ . '/../config/system_settings.json');

/**
 * Get safe system settings with fallback defaults
 */
function getSystemSettings(): array {
    $defaults = [
        'system_name'         => 'FUD RDM System',
        'institution_name'    => 'Federal University Dutse',
        'contact_email'       => 'rdm@fud.edu.ng',
        'items_per_page'      => 20,
        'maintenance_notice'  => '',
        'enable_registration' => true
    ];

    if (file_exists(SYSTEM_SETTINGS_FILE)) {
        $content = @file_get_contents(SYSTEM_SETTINGS_FILE);
        if ($content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return array_merge($defaults, $decoded);
            }
        }
    }

    return $defaults;
}

/**
 * Save safe system settings
 */
function saveSystemSettings(array $settings): bool {
    $clean = [
        'system_name'         => substr(trim($settings['system_name'] ?? 'FUD RDM System'), 0, 150),
        'institution_name'    => substr(trim($settings['institution_name'] ?? 'Federal University Dutse'), 0, 150),
        'contact_email'       => filter_var(trim($settings['contact_email'] ?? 'rdm@fud.edu.ng'), FILTER_VALIDATE_EMAIL) ?: 'rdm@fud.edu.ng',
        'items_per_page'      => max(10, min(50, (int)($settings['items_per_page'] ?? 20))),
        'maintenance_notice'  => substr(trim($settings['maintenance_notice'] ?? ''), 0, 500),
        'enable_registration' => !empty($settings['enable_registration'])
    ];

    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return @file_put_contents(SYSTEM_SETTINGS_FILE, $json, LOCK_EX) !== false;
}
