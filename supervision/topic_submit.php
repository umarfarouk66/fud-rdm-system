<?php
/**
 * Student Topic Submission Controller
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
$topicTitle = sanitize_input($_POST['topic_title'] ?? '');
$abstractSummary = trim($_POST['abstract_summary'] ?? '');
$researchArea = sanitize_input($_POST['research_area'] ?? '');

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid project identifier.';
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
        $_SESSION['project_error'] = 'Access denied: You are not the designated owner of this research project.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 2. Verify active supervisor assignment
    $supervisor = getStudentActiveSupervisor($pdo, $userId);
    if (!$supervisor) {
        $_SESSION['project_error'] = 'Supervisor Not Yet Assigned: You cannot submit a research topic until an academic supervisor has been assigned to you by the University Administrator.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // 3. State transition check
    $allowedStatuses = ['draft', 'planning', 'topic_corrections'];
    if (!in_array($project['status'], $allowedStatuses, true)) {
        $_SESSION['project_error'] = "Cannot submit topic: Project is currently in '{$project['status']}' state.";
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    // Validation
    if (empty($topicTitle)) {
        $_SESSION['project_error'] = 'Research topic title is required.';
        header("Location: ../projects/view.php?id={$projectId}");
        exit;
    }

    $isResubmit = ($project['status'] === 'topic_corrections');

    $pdo->beginTransaction();

    // 4. Upsert project_topics
    $existingTopicStmt = $pdo->prepare("SELECT id FROM project_topics WHERE project_id = :project_id LIMIT 1");
    $existingTopicStmt->execute([':project_id' => $projectId]);
    $existingTopic = $existingTopicStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingTopic) {
        $upTopicSql = "
            UPDATE project_topics 
            SET topic_title = :title,
                abstract_summary = :abstract,
                research_area = :area,
                status = 'submitted',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";
        $upStmt = $pdo->prepare($upTopicSql);
        $upStmt->execute([
            ':title'    => $topicTitle,
            ':abstract' => $abstractSummary,
            ':area'     => $researchArea,
            ':id'       => $existingTopic['id']
        ]);
        $topicId = $existingTopic['id'];
    } else {
        $insTopicSql = "
            INSERT INTO project_topics (
                project_id, student_id, topic_title, abstract_summary, research_area, status
            ) VALUES (
                :project_id, :student_id, :title, :abstract, :area, 'submitted'
            )
        ";
        $insStmt = $pdo->prepare($insTopicSql);
        $insStmt->execute([
            ':project_id' => $projectId,
            ':student_id' => $userId,
            ':title'      => $topicTitle,
            ':abstract'   => $abstractSummary,
            ':area'       => $researchArea
        ]);
        $topicId = $pdo->lastInsertId();
    }

    // 5. Update research_projects status
    $upProjStmt = $pdo->prepare("
        UPDATE research_projects 
        SET title = :title,
            description = :abstract,
            research_area = :area,
            status = 'topic_submitted',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upProjStmt->execute([
        ':title'    => $topicTitle,
        ':abstract' => $abstractSummary,
        ':area'     => $researchArea,
        ':id'       => $projectId
    ]);

    // 6. Notify assigned supervisor
    $supId = (int)$supervisor['supervisor_id'];
    $studentName = $user['first_name'] . ' ' . $user['last_name'];
    $notifAction = $isResubmit ? "resubmitted" : "submitted";
    $notifTitle = $isResubmit ? "Resubmitted Research Topic" : "New Student Research Topic Submitted";
    $notifMessage = "Student {$studentName} has {$notifAction} a research topic '{$topicTitle}' for your review.";
    
    createNotification($pdo, $supId, $notifTitle, $notifMessage, 'topic_submitted', "../projects/view.php?id={$projectId}");

    // 7. Audit log
    $auditAction = $isResubmit ? 'topic_resubmitted' : 'topic_submitted';
    log_audit($pdo, $auditAction, 'research_project', $projectId, "Student {$studentName} {$notifAction} topic '{$topicTitle}' for supervisor review");

    $pdo->commit();

    $_SESSION['project_success'] = $isResubmit 
        ? "Research topic resubmitted successfully! Your supervisor has been notified."
        : "Research topic submitted successfully for supervisor review!";
    header("Location: ../projects/view.php?id={$projectId}");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Topic Submit Controller Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'A system error occurred while submitting your topic. Please try again.';
    header("Location: ../projects/view.php?id={$projectId}");
    exit;
}
