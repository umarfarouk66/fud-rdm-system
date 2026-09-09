<?php
/**
 * Student Chapter & Research Document Submission Controller
 * RDM Information System - Phase 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/supervision_helpers.php';

requireAuth();
enforceWritePermission();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../projects/index.php');
    exit;
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token). Please try again.';
    header('Location: ../projects/index.php');
    exit;
}

$user = currentUser();
$userId = (int)$user['id'];
$projectId = (int)($_POST['project_id'] ?? 0);
$stageType = trim($_POST['stage_type'] ?? '');
$documentTitle = sanitize_input($_POST['document_title'] ?? '');
$submissionNotes = trim($_POST['submission_notes'] ?? '');

$chCount = getProjectChapterCount($pdo, $projectId);
$stages = getChapterStageDefinitions($chCount);

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid research project identifier.';
    header('Location: ../projects/index.php');
    exit;
}

if (!isset($stages[$stageType])) {
    $_SESSION['project_error'] = 'Invalid document stage type specified.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}

$stageDef = $stages[$stageType];

try {
    // 1. Fetch project & verify ownership
    $pStmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'Research project not found.';
        header('Location: ../projects/index.php');
        exit;
    }

    if ((int)$project['owner_id'] !== $userId) {
        $_SESSION['project_error'] = 'Access denied: You are not the designated owner of this research project.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 2. Verify active supervisor assignment
    $supervisor = getStudentActiveSupervisor($pdo, $userId);
    if (!$supervisor) {
        $_SESSION['project_error'] = 'Supervisor Not Yet Assigned: You cannot submit research documents without an active assigned supervisor.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 3. SERVER-SIDE SEQUENTIAL WORKFLOW ENFORCEMENT
    if (!isStageUnlocked($pdo, $projectId, $stageType, $project['status'])) {
        $_SESSION['project_error'] = "Sequential Workflow Restriction: You cannot submit '{$stageDef['label']}' until '{$stageDef['prev_label']}' has been approved by your supervisor.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 4. File upload validation
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['project_error'] = 'Please select a valid document file to upload.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $file = $_FILES['document_file'];
    $originalName = basename($file['name']);
    $fileTmpPath   = $file['tmp_name'];
    $fileSize      = (int)$file['size'];

    // Max 25MB
    $maxBytes = 25 * 1024 * 1024;
    if ($fileSize > $maxBytes) {
        $_SESSION['project_error'] = 'File size exceeds maximum allowed limit (25 MB).';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // Validate extension
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'doc', 'docx', 'odt', 'txt'];
    if (!in_array($ext, $allowedExts, true)) {
        $_SESSION['project_error'] = 'Invalid file format. Allowed types: .pdf, .doc, .docx, .odt, .txt';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'text/plain',
        'application/octet-stream'
    ];

    if (!in_array($mimeType, $allowedMimes, true)) {
        $_SESSION['project_error'] = "Invalid MIME file type ({$mimeType}). Upload a valid document file.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $checksum = hash_file('sha256', $fileTmpPath);

    // 5. Query submission & determine version number
    $subStmt = $pdo->prepare("
        SELECT * FROM project_submissions 
        WHERE project_id = :project_id AND submission_type = :stype 
        LIMIT 1
    ");
    $subStmt->execute([':project_id' => $projectId, ':stype' => $stageType]);
    $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

    $isResubmit = ($existingSub && in_array($existingSub['status'], ['corrections_required', 'under_review'], true));

    $pdo->beginTransaction();

    if ($existingSub) {
        $submissionId = (int)$existingSub['id'];
        $newSubNumber = (int)$existingSub['submission_number'] + 1;

        $vNumStmt = $pdo->prepare("SELECT MAX(version_number) FROM submission_versions WHERE submission_id = :sub_id");
        $vNumStmt->execute([':sub_id' => $submissionId]);
        $maxVersion = (int)$vNumStmt->fetchColumn();
        $newVersionNumber = $maxVersion + 1;

        $upSubStmt = $pdo->prepare("
            UPDATE project_submissions 
            SET title = :title,
                submission_number = :sub_num,
                status = 'submitted',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $upSubStmt->execute([
            ':title'   => !empty($documentTitle) ? $documentTitle : "{$stageDef['short_name']} v{$newVersionNumber}",
            ':sub_num' => $newSubNumber,
            ':id'      => $submissionId
        ]);
    } else {
        $newVersionNumber = 1;
        $insSubStmt = $pdo->prepare("
            INSERT INTO project_submissions (
                project_id, submitted_by, title, submission_type, submission_number, status
            ) VALUES (
                :project_id, :submitted_by, :title, :stype, 1, 'submitted'
            )
        ");
        $insSubStmt->execute([
            ':project_id'   => $projectId,
            ':submitted_by' => $userId,
            ':title'        => !empty($documentTitle) ? $documentTitle : "{$stageDef['short_name']} v1",
            ':stype'        => $stageType
        ]);
        $submissionId = (int)$pdo->lastInsertId();
    }

    // Generate safe stored filename inside storage/chapters/
    $randomHash = bin2hex(random_bytes(8));
    $storedFileName = "{$stageType}_{$projectId}_v{$newVersionNumber}_{$randomHash}.{$ext}";
    $storageDir = __DIR__ . '/../storage/chapters/';
    
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    $destinationPath = $storageDir . $storedFileName;
    $relativePath    = 'storage/chapters/' . $storedFileName;

    if (!move_uploaded_file($fileTmpPath, $destinationPath)) {
        throw new Exception("Failed to move uploaded document to server storage.");
    }

    // 6. Insert submission_versions record
    $insVerStmt = $pdo->prepare("
        INSERT INTO submission_versions (
            submission_id, version_number, file_name, stored_file_name, file_path, file_size, mime_type, checksum, uploaded_by, notes
        ) VALUES (
            :sub_id, :v_num, :orig_name, :stored_name, :path, :size, :mime, :chk, :uploader, :notes
        )
    ");
    $insVerStmt->execute([
        ':sub_id'      => $submissionId,
        ':v_num'       => $newVersionNumber,
        ':orig_name'   => $originalName,
        ':stored_name' => $storedFileName,
        ':path'        => $relativePath,
        ':size'        => $fileSize,
        ':mime'        => $mimeType,
        ':chk'         => $checksum,
        ':uploader'    => $userId,
        ':notes'       => $submissionNotes
    ]);

    // 7. Update research_projects status
    $newProjStatus = "{$stageType}_submitted";
    $upProjStmt = $pdo->prepare("
        UPDATE research_projects 
        SET status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upProjStmt->execute([':status' => $newProjStatus, ':id' => $projectId]);

    // 8. Notify supervisor
    $supId = (int)$supervisor['supervisor_id'];
    $studentName = $user['first_name'] . ' ' . $user['last_name'];
    $notifTitle = "New Submission: {$stageDef['short_name']} (v{$newVersionNumber})";
    $notifMsg   = "Student {$studentName} uploaded {$stageDef['short_name']} v{$newVersionNumber} for project '{$project['title']}'.";
    
    createNotification($pdo, $supId, $notifTitle, $notifMsg, 'chapter_submitted', "../projects/view.php?id={$projectId}");

    // 9. Audit log
    $auditAction = $isResubmit ? 'chapter_resubmitted' : 'chapter_submitted';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Student {$studentName} submitted {$stageDef['short_name']} v{$newVersionNumber} ({$originalName})");

    $pdo->commit();

    $_SESSION['project_success'] = "{$stageDef['short_name']} (v{$newVersionNumber}) uploaded and submitted successfully! Your supervisor has been notified.";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Chapter Submit Exception: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while uploading document: ' . $e->getMessage();
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
