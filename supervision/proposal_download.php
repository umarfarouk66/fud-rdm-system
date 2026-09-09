<?php
/**
 * Controlled Download Endpoint for Proposal Files
 * RDM Information System - Phase 4 Security
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/supervision_helpers.php';

requireAuth();

$user = currentUser();
$userId = (int)$user['id'];
$systemRole = $user['role'];
$versionId = (int)($_GET['version_id'] ?? 0);

if ($versionId <= 0) {
    http_response_code(400);
    die('400 Bad Request: Invalid version identifier.');
}

try {
    // Fetch version details + submission + project
    $stmt = $pdo->prepare("
        SELECT 
            v.*,
            s.project_id,
            s.submission_type,
            p.owner_id,
            p.title AS project_title
        FROM submission_versions v
        INNER JOIN project_submissions s ON v.submission_id = s.id
        INNER JOIN research_projects p ON s.project_id = p.id
        WHERE v.id = :v_id
        LIMIT 1
    ");
    $stmt->execute([':v_id' => $versionId]);
    $ver = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ver) {
        http_response_code(404);
        die('404 Not Found: Proposal version record not found.');
    }

    $projectId = (int)$ver['project_id'];
    $ownerId   = (int)$ver['owner_id'];

    // Authorization checks
    $isOwner      = ($userId === $ownerId);
    $isSupervisor = isSupervisorOfStudent($pdo, $userId, $ownerId);
    $memberRole   = getProjectMemberRole($pdo, $projectId, $userId);
    $isAdmin      = in_array($systemRole, ['admin', 'super_admin'], true);

    if (!$isOwner && !$isSupervisor && !$memberRole && !$isAdmin) {
        http_response_code(403);
        die('403 Forbidden: You do not have permission to access or download this proposal document.');
    }

    // Resolve file path safely
    $storedFileName = basename($ver['stored_file_name']);
    $fullPath = __DIR__ . '/../storage/proposals/' . $storedFileName;

    // Fallback to relative file_path if different
    if (!file_exists($fullPath)) {
        $fullPath = __DIR__ . '/../' . ltrim($ver['file_path'], '/\\');
    }

    if (!file_exists($fullPath) || !is_file($fullPath)) {
        http_response_code(404);
        die('404 Not Found: The requested proposal file does not exist on disk.');
    }

    // Sanitize output filename
    $outputFileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $ver['file_name']);
    $mimeType = $ver['mime_type'] ?: 'application/octet-stream';
    $fileSize = filesize($fullPath);

    // Clear output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $outputFileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . $fileSize);

    readfile($fullPath);
    exit;

} catch (Exception $e) {
    error_log("Proposal Download Error: " . $e->getMessage());
    http_response_code(500);
    die('500 Internal Server Error');
}
