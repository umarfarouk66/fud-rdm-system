<?php
/**
 * Project Access Control and Utility Functions
 * RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

/**
 * Determine a user's role within a specific project
 * 
 * @param PDO $pdo
 * @param int $projectId
 * @param int $userId
 * @return string|null 'owner'|'manager'|'editor'|'uploader'|'viewer' or null
 */
function getProjectMemberRole(PDO $pdo, int $projectId, int $userId): ?string {
    try {
        // 1. Check if user is the designated owner in research_projects
        $ownerStmt = $pdo->prepare("SELECT owner_id FROM research_projects WHERE id = :project_id LIMIT 1");
        $ownerStmt->execute([':project_id' => $projectId]);
        $project = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        if ($project && (int)$project['owner_id'] === $userId) {
            return 'owner';
        }

        // 2. Check project_members table
        $memberStmt = $pdo->prepare("
            SELECT role 
            FROM project_members 
            WHERE project_id = :project_id AND user_id = :user_id 
            LIMIT 1
        ");
        $memberStmt->execute([
            ':project_id' => $projectId,
            ':user_id'    => $userId
        ]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

        return $member ? strtolower($member['role']) : null;
    } catch (PDOException $e) {
        error_log("Project Member Role Check Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if user can view project details based on status and user roles
 */
function canViewProject(?string $projectRole, ?string $systemRole, string $projectStatus = 'active', int $ownerId = 0, int $userId = 0): bool {
    // All authenticated users and repository visitors can view research project overview details
    return true;
}

/**
 * Check if user can edit project information
 */
function canEditProject(?string $projectRole, ?string $systemRole): bool {
    if ($projectRole && in_array($projectRole, ['owner', 'manager'], true)) {
        return true;
    }
    return strtolower($systemRole ?? '') === 'admin';
}

/**
 * Check if user can delete the project
 */
function canDeleteProject(?string $projectRole, ?string $systemRole): bool {
    if ($projectRole === 'owner') {
        return true;
    }
    return strtolower($systemRole ?? '') === 'admin';
}

/**
 * Check if user can add, update, or remove project team members
 */
function canManageMembers(?string $projectRole, ?string $systemRole): bool {
    if ($projectRole && in_array($projectRole, ['owner', 'manager'], true)) {
        return true;
    }
    return strtolower($systemRole ?? '') === 'admin';
}

/**
 * Generate a unique research project code (Format: RDM-YYYY-XXXXX)
 */
function generateProjectCode(PDO $pdo): string {
    $year = date('Y');
    $prefix = "RDM-{$year}-";

    // Query highest existing code for current year to determine sequence number
    $stmt = $pdo->prepare("
        SELECT project_code 
        FROM research_projects 
        WHERE project_code LIKE :prefix 
        ORDER BY id DESC 
        LIMIT 100
    ");
    $stmt->execute([':prefix' => "{$prefix}%"]);
    $existingCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxSequence = 0;
    foreach ($existingCodes as $code) {
        $parts = explode('-', $code);
        if (count($parts) >= 3 && is_numeric($parts[2])) {
            $num = (int)$parts[2];
            if ($num > $maxSequence) {
                $maxSequence = $num;
            }
        }
    }

    $sequence = $maxSequence + 1;

    do {
        $candidateCode = sprintf("RDM-%s-%05d", $year, $sequence);
        $checkStmt = $pdo->prepare("SELECT id FROM research_projects WHERE project_code = :code LIMIT 1");
        $checkStmt->execute([':code' => $candidateCode]);
        $exists = (bool)$checkStmt->fetch();
        if ($exists) {
            $sequence++;
        }
    } while ($exists);

    return $candidateCode;
}
