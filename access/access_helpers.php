<?php
/**
 * Dataset Access Requests & Sharing Helpers
 * RDM Information System - Step 9
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';

/**
 * Check if a user has an approved access request for a dataset
 */
function hasApprovedAccessRequest(PDO $pdo, int $datasetId, int $userId): bool {
    if ($datasetId <= 0 || $userId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM access_requests 
            WHERE dataset_id = :dataset_id 
              AND requester_id = :user_id 
              AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([':dataset_id' => $datasetId, ':user_id' => $userId]);
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("Approved Access Check Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get the latest access request submitted by a user for a dataset
 */
function getUserLatestAccessRequest(PDO $pdo, int $datasetId, int $userId): ?array {
    if ($datasetId <= 0 || $userId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM access_requests 
            WHERE dataset_id = :dataset_id AND requester_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':dataset_id' => $datasetId, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log("User Latest Access Request Query Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if a user has permission to review access requests for a dataset
 */
function canManageAccessRequests(?string $projectRole, ?string $systemRole, int $datasetOwnerId, int $userId): bool {
    if ($userId === $datasetOwnerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    return $projectRole && in_array($projectRole, ['owner', 'manager'], true);
}

/**
 * Render HTML status badge for access request status
 */
function getRequestStatusBadge(string $status): string {
    switch (strtolower(trim($status))) {
        case 'approved':
            return '<span class="status-badge status-approved" style="background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Approved</span>';
        case 'rejected':
            return '<span class="status-badge status-rejected" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Rejected</span>';
        case 'revoked':
            return '<span class="status-badge status-revoked" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Revoked</span>';
        case 'pending':
        default:
            return '<span class="status-badge status-pending" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Pending Review</span>';
    }
}
