<?php
/**
 * Delete Research Project Controller
 * RDM Information System - Step 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

// Enforce authentication
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$projectId = (int)($_POST['project_id'] ?? 0);

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid project identifier for deletion.';
    header('Location: index.php');
    exit;
}

// Validate CSRF token
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token). Deletion aborted.';
    header("Location: view.php?id={$projectId}");
    exit;
}

try {
    // Check project existence
    $stmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :project_id LIMIT 1");
    $stmt->execute([':project_id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'The project you attempted to delete does not exist.';
        header('Location: index.php');
        exit;
    }

    // Check delete permission (strictly owner or system admin)
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    if (!canDeleteProject($projectRole, $systemRole)) {
        $_SESSION['project_error'] = 'Access denied: Only the designated project owner or system administrator can delete this project.';
        header("Location: view.php?id={$projectId}");
        exit;
    }

    // Execute deletion within transaction
    $pdo->beginTransaction();

    // 1. Record audit log before deletion
    log_audit(
        $pdo,
        'project_deleted',
        'research_project',
        $projectId,
        "Permanently deleted research project '{$project['title']}' ({$project['project_code']})"
    );

    // 2. Delete project (cascades to project_members)
    $deleteStmt = $pdo->prepare("DELETE FROM research_projects WHERE id = :id");
    $deleteStmt->execute([':id' => $projectId]);

    $pdo->commit();

    $_SESSION['project_success'] = "Research project '{$project['title']}' ({$project['project_code']}) has been permanently deleted.";
    header('Location: index.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Project Delete Exception: " . $e->getMessage());
    $_SESSION['project_error'] = 'A database error occurred while deleting the project.';
    header("Location: view.php?id={$projectId}");
    exit;
}
