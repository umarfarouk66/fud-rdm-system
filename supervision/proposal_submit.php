<?php
/**
 * Student Proposal Submission Controller
 * RDM Information System - Phase 4
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
$proposalTitle = sanitize_input($_POST['proposal_title'] ?? '');
$submissionNotes = trim($_POST['submission_notes'] ?? '');

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid research project identifier.';
    header('Location: ../projects/index.php');
    exit;
}

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
        $_SESSION['project_error'] = 'Access denied: You are not the owner of this project.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 2. Verify active supervisor assignment
    $supervisor = getStudentActiveSupervisor($pdo, $userId);
    if (!$supervisor) {
        $_SESSION['project_error'] = 'Supervisor Not Yet Assigned: You cannot submit a proposal without an active assigned supervisor.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 3. Prerequisite check: Topic MUST be approved
    $allowedStatuses = ['topic_approved', 'proposal_corrections'];
    if (!in_array($project['status'], $allowedStatuses, true)) {
        $_SESSION['project_error'] = "You cannot submit a research proposal until your topic has been approved by your supervisor. (Current status: {$project['status']})";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 4. File upload validation
    if (!isset($_FILES['proposal_file']) || $_FILES['proposal_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['project_error'] = 'Please select a valid proposal document to upload.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $file = $_FILES['proposal_file'];
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
        $_SESSION['project_error'] = 'Invalid file extension. Allowed formats: .pdf, .doc, .docx, .odt, .txt';
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
        'application/octet-stream' // fallback for some word files
    ];

    if (!in_array($mimeType, $allowedMimes, true)) {
        $_SESSION['project_error'] = "Invalid file type ({$mimeType}). Please upload a valid proposal document.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $checksum = hash_file('sha256', $fileTmpPath);

    // 5. Determine submission & version numbers
    $subStmt = $pdo->prepare("
        SELECT * FROM project_submissions 
        WHERE project_id = :project_id AND submission_type = 'proposal' 
        LIMIT 1
    ");
    $subStmt->execute([':project_id' => $projectId]);
    $existingSub = $subStmt->fetch(PDO::FETCH_ASSOC);

    $isResubmit = ($project['status'] === 'proposal_corrections');

    $pdo->beginTransaction();

    if ($existingSub) {
        $submissionId = (int)$existingSub['id'];
        $newSubNumber = (int)$existingSub['submission_number'] + 1;

        // Fetch max version number
        $vNumStmt = $pdo->prepare("SELECT MAX(version_number) FROM submission_versions WHERE submission_id = :sub_id");
        $vNumStmt->execute([':sub_id' => $submissionId]);
        $maxVersion = (int)$vNumStmt->fetchColumn();
        $newVersionNumber = $maxVersion + 1;

        // Update project_submissions
        $upSubStmt = $pdo->prepare("
            UPDATE project_submissions 
            SET title = :title,
                submission_number = :sub_num,
                status = 'submitted',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $upSubStmt->execute([
            ':title'   => !empty($proposalTitle) ? $proposalTitle : "Research Proposal v{$newVersionNumber}",
            ':sub_num' => $newSubNumber,
            ':id'      => $submissionId
        ]);
    } else {
        $newVersionNumber = 1;
        $insSubStmt = $pdo->prepare("
            INSERT INTO project_submissions (
                project_id, submitted_by, title, submission_type, submission_number, status
            ) VALUES (
                :project_id, :submitted_by, :title, 'proposal', 1, 'submitted'
            )
        ");
        $insSubStmt->execute([
            ':project_id'   => $projectId,
            ':submitted_by' => $userId,
            ':title'        => !empty($proposalTitle) ? $proposalTitle : "Research Proposal v1"
        ]);
        $submissionId = (int)$pdo->lastInsertId();
    }

    // Generate safe stored filename inside storage/proposals/
    $randomHash = bin2hex(random_bytes(8));
    $storedFileName = "proposal_{$projectId}_v{$newVersionNumber}_{$randomHash}.{$ext}";
    $storageDir = __DIR__ . '/../storage/proposals/';
    
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    $destinationPath = $storageDir . $storedFileName;
    $relativePath    = 'storage/proposals/' . $storedFileName;

    if (!move_uploaded_file($fileTmpPath, $destinationPath)) {
        throw new Exception("Failed to save uploaded file to server storage.");
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
    $upProjStmt = $pdo->prepare("
        UPDATE research_projects 
        SET status = 'proposal_submitted',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upProjStmt->execute([':id' => $projectId]);

    // 8. Notify supervisor
    $supId = (int)$supervisor['supervisor_id'];
    $studentName = $user['first_name'] . ' ' . $user['last_name'];
    $notifTitle = $isResubmit ? "Resubmitted Research Proposal (v{$newVersionNumber})" : "New Research Proposal Submitted (v1)";
    $notifMsg   = "Student {$studentName} submitted research proposal v{$newVersionNumber} for project '{$project['title']}'.";
    
    createNotification($pdo, $supId, $notifTitle, $notifMsg, 'proposal_submitted', "../projects/view.php?id={$projectId}");

    // 9. Audit log
    $auditAction = $isResubmit ? 'proposal_resubmitted' : 'proposal_submitted';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Student {$studentName} submitted proposal v{$newVersionNumber} ({$originalName})");

    $pdo->commit();

    $_SESSION['project_success'] = "Proposal document (v{$newVersionNumber}) submitted successfully! Your supervisor has been notified.";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Proposal Submit Exception: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while uploading your proposal: ' . $e->getMessage();
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
