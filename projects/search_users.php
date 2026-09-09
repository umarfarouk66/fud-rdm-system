<?php
/**
 * Searchable User Endpoint for Team Member Addition
 * FUD RDM System - Step 5 Collaboration & Security
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

header('Content-Type: application/json; charset=UTF-8');

// 1. Enforce authenticated session
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$user = currentUser();
$userId = (int)$user['id'];
$systemRole = $user['role'];

$projectId = (int)($_GET['project_id'] ?? 0);
$rawQuery  = trim($_GET['q'] ?? '');

if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid project identifier.']);
    exit;
}

try {
    // 2. Determine user's role in this project and enforce authorization
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    if (!canManageMembers($projectRole, $systemRole)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied: You are not authorized to manage team members for this project.']);
        exit;
    }

    if ($rawQuery === '') {
        echo json_encode([]);
        exit;
    }

    // 3. Prepare search pattern
    $likeQuery = '%' . $rawQuery . '%';
    $exactId   = is_numeric($rawQuery) ? (int)$rawQuery : -1;

    // 4. Query active users who are not already project team members
    // Select ONLY safe, necessary presentation fields (NO passwords, NO hashes, NO sensitive tokens)
    $sql = "
        SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.department, 
            u.institution,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.status = 'active'
          AND u.id NOT IN (
              SELECT user_id FROM project_members WHERE project_id = :project_id
          )
          AND (
              u.first_name LIKE :q1
              OR u.last_name LIKE :q2
              OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q3
              OR u.email LIKE :q4
              OR u.id = :exact_id
          )
        ORDER BY u.first_name ASC, u.last_name ASC
        LIMIT 15
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':project_id' => $projectId,
        ':q1'         => $likeQuery,
        ':q2'         => $likeQuery,
        ':q3'         => $likeQuery,
        ':q4'         => $likeQuery,
        ':exact_id'   => $exactId
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($users);
    exit;

} catch (PDOException $e) {
    error_log("User Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An internal database error occurred while searching users.']);
    exit;
}
