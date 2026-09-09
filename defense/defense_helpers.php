<?php
/**
 * Defense & Viva Management Helpers
 * FUD RDM System - Phase 8: Final Project Approval + Defense/Viva Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';

/**
 * Check comprehensive final readiness checklist for a research project.
 */
function checkFinalProjectReadiness(PDO $pdo, int $projectId): array {
    $readiness = [
        'is_ready'    => false,
        'ready_count' => 0,
        'total_count' => 7,
        'checklist'   => []
    ];

    if ($projectId <= 0) {
        return $readiness;
    }

    $projectData = getProjectSupervisionData($pdo, $projectId);
    $project     = $projectData['project'] ?? null;

    if (!$project) {
        return $readiness;
    }

    $pStatus = strtolower($project['status']);

    // 1. Topic Approval
    $topicApproved = in_array($pStatus, [
        'topic_approved', 'proposal_submitted', 'proposal_under_review', 'proposal_corrections', 'proposal_approved',
        'chapter_1_submitted', 'chapter_1_approved', 'chapter_2_submitted', 'chapter_2_approved',
        'chapter_3_submitted', 'chapter_3_approved', 'chapter_4_submitted', 'chapter_4_approved',
        'chapter_5_submitted', 'chapter_5_approved', 'full_draft_submitted', 'full_draft_approved',
        'final_submission_submitted', 'final_submission_approved', 'in_progress', 'defense_scheduled',
        'defense_completed', 'final_corrections', 'approved', 'completed'
    ], true);

    $readiness['checklist'][] = [
        'key'     => 'topic_approved',
        'label'   => 'Research Topic Approval',
        'passed'  => $topicApproved,
        'details' => $topicApproved ? 'Approved by Supervisor' : 'Pending Topic Approval'
    ];

    // 2. Proposal Approval
    $proposalApproved = in_array($pStatus, [
        'proposal_approved', 'chapter_1_submitted', 'chapter_1_approved', 'chapter_2_submitted', 'chapter_2_approved',
        'chapter_3_submitted', 'chapter_3_approved', 'chapter_4_submitted', 'chapter_4_approved',
        'chapter_5_submitted', 'chapter_5_approved', 'full_draft_submitted', 'full_draft_approved',
        'final_submission_submitted', 'final_submission_approved', 'in_progress', 'defense_scheduled',
        'defense_completed', 'final_corrections', 'approved', 'completed'
    ], true);

    $readiness['checklist'][] = [
        'key'     => 'proposal_approved',
        'label'   => 'Research Proposal Approval',
        'passed'  => $proposalApproved,
        'details' => $proposalApproved ? 'Approved by Supervisor' : 'Pending Proposal Approval'
    ];

    // 3. Chapters 1-5 Progress
    $chaptersApprovedCount = 0;
    foreach ($projectData['chapters'] as $type => $cItem) {
        if ($cItem['submission'] && $cItem['submission']['status'] === 'approved') {
            $chaptersApprovedCount++;
        }
    }
    $chaptersPassed = ($chaptersApprovedCount >= 5) || in_array($pStatus, [
        'full_draft_submitted', 'full_draft_approved', 'final_submission_submitted', 'final_submission_approved',
        'defense_scheduled', 'defense_completed', 'final_corrections', 'approved', 'completed'
    ], true);

    $readiness['checklist'][] = [
        'key'     => 'chapters_approved',
        'label'   => 'Chapters 1–5 Document Approvals',
        'passed'  => $chaptersPassed,
        'details' => $chaptersPassed ? "Required Chapters Completed" : "{$chaptersApprovedCount} / 5 Chapters Approved"
    ];

    // 4. Open Requested Corrections Resolution
    $openCorrectionsCount = 0;
    foreach ($projectData['corrections'] as $cor) {
        if ($cor['status'] !== 'accepted') {
            $openCorrectionsCount++;
        }
    }
    $correctionsPassed = ($openCorrectionsCount === 0);

    $readiness['checklist'][] = [
        'key'     => 'corrections_resolved',
        'label'   => 'Supervisor Requested Corrections Resolved',
        'passed'  => $correctionsPassed,
        'details' => $correctionsPassed ? 'All requested corrections verified & accepted' : "{$openCorrectionsCount} correction item(s) pending resolution"
    ];

    // 5. Project Milestones Completion
    $completedMilestonesCount = 0;
    $totalMilestones = count($projectData['milestones']);
    foreach ($projectData['milestones'] as $m) {
        if ($m['status'] === 'completed') {
            $completedMilestonesCount++;
        }
    }
    $milestonesPassed = ($totalMilestones > 0 && $completedMilestonesCount >= 7);

    $readiness['checklist'][] = [
        'key'     => 'milestones_completed',
        'label'   => 'Supervision Milestones Completed',
        'passed'  => $milestonesPassed,
        'details' => "{$completedMilestonesCount} / {$totalMilestones} Milestones Completed"
    ];

    // 6. Full / Final Draft Submission
    $finalDraftSubmitted = false;
    $fdSubStmt = $pdo->prepare("
        SELECT id, status FROM project_submissions 
        WHERE project_id = :pid AND submission_type IN ('full_draft', 'defense_draft', 'final_submission')
        ORDER BY id DESC LIMIT 1
    ");
    $fdSubStmt->execute([':pid' => $projectId]);
    $fdSub = $fdSubStmt->fetch(PDO::FETCH_ASSOC);
    if ($fdSub) {
        $finalDraftSubmitted = true;
    }
    $readiness['checklist'][] = [
        'key'     => 'final_draft_submitted',
        'label'   => 'Full Draft / Final Project Document Submitted',
        'passed'  => $finalDraftSubmitted,
        'details' => $finalDraftSubmitted ? 'Final draft document uploaded' : 'Awaiting final draft upload'
    ];

    // 7. Supervisor Final Approval for Defense
    $supervisorApprovedForDefense = in_array($pStatus, [
        'final_submission_approved', 'defense_scheduled', 'defense_completed', 'final_corrections', 'approved', 'completed'
    ], true);

    $readiness['checklist'][] = [
        'key'     => 'supervisor_final_approval',
        'label'   => 'Supervisor Defense Authorization',
        'passed'  => $supervisorApprovedForDefense,
        'details' => $supervisorApprovedForDefense ? 'Approved for Defense by Supervisor' : 'Pending Supervisor Final Approval'
    ];

    // Calculate total passed
    $passedCount = 0;
    foreach ($readiness['checklist'] as $item) {
        if ($item['passed']) $passedCount++;
    }

    $readiness['ready_count'] = $passedCount;
    $readiness['is_ready']    = ($passedCount >= 6); // At least 6 of 7 criteria met

    return $readiness;
}

/**
 * Fetch defense details for a research project.
 */
function getProjectDefense(PDO $pdo, int $projectId): ?array {
    $stmt = $pdo->prepare("
        SELECT d.*, u.first_name AS creator_first, u.last_name AS creator_last
        FROM project_defenses d
        INNER JOIN users u ON d.created_by = u.id
        WHERE d.project_id = :pid LIMIT 1
    ");
    $stmt->execute([':pid' => $projectId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Fetch panel members for a scheduled defense.
 */
function getDefensePanel(PDO $pdo, int $defenseId): array {
    $stmt = $pdo->prepare("
        SELECT 
            pm.*,
            u.first_name, u.last_name, u.email, u.department, u.faculty, r.name AS user_role
        FROM defense_panel_members pm
        LEFT JOIN users u ON pm.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE pm.defense_id = :did
        ORDER BY 
            CASE pm.panel_role 
                WHEN 'chair' THEN 1
                WHEN 'external_examiner' THEN 2
                WHEN 'internal_examiner' THEN 3
                WHEN 'supervisor_member' THEN 4
                ELSE 5
            END, pm.created_at ASC
    ");
    $stmt->execute([':did' => $defenseId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch recorded defense outcome details.
 */
function getDefenseOutcome(PDO $pdo, int $defenseId): ?array {
    $stmt = $pdo->prepare("
        SELECT o.*, u.first_name AS recorder_first, u.last_name AS recorder_last
        FROM defense_outcomes o
        INNER JOIN users u ON o.recorded_by = u.id
        WHERE o.defense_id = :did LIMIT 1
    ");
    $stmt->execute([':did' => $defenseId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Schedule or update a project defense.
 */
function scheduleProjectDefense(PDO $pdo, int $projectId, array $data, int $adminId): array {
    if ($projectId <= 0 || $adminId <= 0) {
        return ['success' => false, 'error' => 'Invalid project or administrator ID.'];
    }

    $title        = trim($data['defense_title'] ?? 'Final Oral Project Defense (Viva Voce)');
    $date         = trim($data['defense_date'] ?? '');
    $startTime    = trim($data['start_time'] ?? '');
    $endTime      = trim($data['end_time'] ?? '');
    $venue        = trim($data['venue'] ?? '');
    $roomLocation = trim($data['room_location'] ?? '');
    $defenseType  = trim($data['defense_type'] ?? 'final_defense');
    $instructions = trim($data['instructions'] ?? '');

    if (empty($date) || empty($startTime) || empty($venue)) {
        return ['success' => false, 'error' => 'Defense date, start time, and venue location are required.'];
    }

    // Check project exists
    $pStmt = $pdo->prepare("SELECT id, owner_id, title FROM research_projects WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        return ['success' => false, 'error' => 'Research project record not found.'];
    }

    $studentId = (int)$project['owner_id'];

    // Check existing defense record
    $existing = getProjectDefense($pdo, $projectId);

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE project_defenses 
            SET defense_title = :title,
                defense_date = :date,
                start_time = :stime,
                end_time = :etime,
                venue = :venue,
                room_location = :room,
                defense_type = :type,
                status = 'scheduled',
                instructions = :instructions,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':title'        => $title,
            ':date'         => $date,
            ':stime'        => $startTime,
            ':etime'        => !empty($endTime) ? $endTime : null,
            ':venue'        => $venue,
            ':room'         => !empty($roomLocation) ? $roomLocation : null,
            ':type'         => $defenseType,
            ':instructions' => !empty($instructions) ? $instructions : null,
            ':id'           => $existing['id']
        ]);
        $defenseId = (int)$existing['id'];
    } else {
        $ins = $pdo->prepare("
            INSERT INTO project_defenses (
                project_id, defense_title, defense_date, start_time, end_time, 
                venue, room_location, defense_type, status, instructions, created_by, created_at, updated_at
            ) VALUES (
                :pid, :title, :date, :stime, :etime,
                :venue, :room, :type, 'scheduled', :instructions, :uid, NOW(), NOW()
            )
        ");
        $ins->execute([
            ':pid'          => $projectId,
            ':title'        => $title,
            ':date'         => $date,
            ':stime'        => $startTime,
            ':etime'        => !empty($endTime) ? $endTime : null,
            ':venue'        => $venue,
            ':room'         => !empty($roomLocation) ? $roomLocation : null,
            ':type'         => $defenseType,
            ':instructions' => !empty($instructions) ? $instructions : null,
            ':uid'          => $adminId
        ]);
        $defenseId = (int)$pdo->lastInsertId();
    }

    // Update project status to defense_scheduled
    $updProj = $pdo->prepare("UPDATE research_projects SET status = 'defense_scheduled', updated_at = NOW() WHERE id = :id");
    $updProj->execute([':id' => $projectId]);

    // Recalculate progress & sync milestones
    syncProjectMilestoneStatuses($pdo, $projectId);

    // Notifications
    $notifMsg = "Your final oral defense '{$title}' has been scheduled for " . date('M d, Y', strtotime($date)) . " at {$startTime} in {$venue}.";
    createNotification($pdo, $studentId, 'defense_scheduled', '🎓 Defense Scheduled', $notifMsg, "../projects/view.php?id={$projectId}");

    // Supervisor Notification
    $supStmt = $pdo->prepare("SELECT supervisor_id FROM student_supervisors WHERE student_id = :sid AND status = 'active'");
    $supStmt->execute([':sid' => $studentId]);
    $supervisors = $supStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($supervisors as $supId) {
        createNotification($pdo, (int)$supId, 'defense_scheduled', '🎓 Student Defense Scheduled', "Student project '{$project['title']}' defense scheduled for " . date('M d, Y', strtotime($date)) . ".", "../supervisor/student_profile.php?student_id={$studentId}");
    }

    // Audit log
    log_audit($pdo, 'defense_scheduled', 'project_defense', $defenseId, "Defense scheduled for project '{$project['title']}' on {$date} at {$venue}.");

    return ['success' => true, 'defense_id' => $defenseId];
}

/**
 * Record or update defense examination outcome.
 */
function recordDefenseOutcome(PDO $pdo, int $defenseId, array $data, int $recordedBy): array {
    $outcome    = trim($data['outcome'] ?? '');
    $remarks    = trim($data['overall_remarks'] ?? '');
    $corrSumm   = trim($data['corrections_required_summary'] ?? '');
    $date       = trim($data['decision_date'] ?? date('Y-m-d'));

    $validOutcomes = [
        'passed', 'passed_with_minor_corrections', 'major_corrections_required',
        're_defense_required', 'failed', 'deferred'
    ];

    if (!in_array($outcome, $validOutcomes, true)) {
        return ['success' => false, 'error' => 'Invalid defense outcome selection.'];
    }

    // Fetch defense record
    $dStmt = $pdo->prepare("SELECT d.*, rp.owner_id AS student_id, rp.title AS project_title FROM project_defenses d INNER JOIN research_projects rp ON d.project_id = rp.id WHERE d.id = :id LIMIT 1");
    $dStmt->execute([':id' => $defenseId]);
    $defense = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$defense) {
        return ['success' => false, 'error' => 'Defense schedule record not found.'];
    }

    $projectId = (int)$defense['project_id'];
    $studentId = (int)$defense['student_id'];

    // Check existing outcome
    $existing = getDefenseOutcome($pdo, $defenseId);

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE defense_outcomes 
            SET outcome = :outcome,
                overall_remarks = :remarks,
                corrections_required_summary = :csum,
                decision_date = :ddate,
                recorded_by = :uid,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':outcome' => $outcome,
            ':remarks' => !empty($remarks) ? $remarks : null,
            ':csum'    => !empty($corrSumm) ? $corrSumm : null,
            ':ddate'   => $date,
            ':uid'     => $recordedBy,
            ':id'      => $existing['id']
        ]);
        $outcomeId = (int)$existing['id'];
    } else {
        $ins = $pdo->prepare("
            INSERT INTO defense_outcomes (
                defense_id, project_id, outcome, overall_remarks, 
                corrections_required_summary, decision_date, recorded_by, created_at, updated_at
            ) VALUES (
                :did, :pid, :outcome, :remarks, :csum, :ddate, :uid, NOW(), NOW()
            )
        ");
        $ins->execute([
            ':did'     => $defenseId,
            ':pid'     => $projectId,
            ':outcome' => $outcome,
            ':remarks' => !empty($remarks) ? $remarks : null,
            ':csum'    => !empty($corrSumm) ? $corrSumm : null,
            ':ddate'   => $date,
            ':uid'     => $recordedBy
        ]);
        $outcomeId = (int)$pdo->lastInsertId();
    }

    // Update defense status to completed
    $updDef = $pdo->prepare("UPDATE project_defenses SET status = 'completed', updated_at = NOW() WHERE id = :did");
    $updDef->execute([':did' => $defenseId]);

    // Map outcome to project status
    $newProjectStatus = 'defense_completed';
    if (in_array($outcome, ['passed_with_minor_corrections', 'major_corrections_required'], true)) {
        $newProjectStatus = 'final_corrections';
    } elseif ($outcome === 'passed') {
        $newProjectStatus = 'defense_completed';
    } elseif ($outcome === 'failed') {
        $newProjectStatus = 'in_progress';
    }

    $updProj = $pdo->prepare("UPDATE research_projects SET status = :status, updated_at = NOW() WHERE id = :pid");
    $updProj->execute([':status' => $newProjectStatus, ':pid' => $projectId]);

    // If corrections required, automatically log item in project_corrections
    if (!empty($corrSumm) && in_array($outcome, ['passed_with_minor_corrections', 'major_corrections_required'], true)) {
        $insCorr = $pdo->prepare("
            INSERT INTO project_corrections (
                project_id, version_number, correction_title, correction_details, 
                requested_by, status, created_at, updated_at
            ) VALUES (
                :pid, 1, :title, :details, :uid, 'open', NOW(), NOW()
            )
        ");
        $insCorr->execute([
            ':pid'     => $projectId,
            ':title'   => 'Post-Defense Panel Corrections (' . str_replace('_', ' ', ucfirst($outcome)) . ')',
            ':details' => $corrSumm,
            ':uid'     => $recordedBy
        ]);
    }

    // Recalculate progress & sync milestones
    syncProjectMilestoneStatuses($pdo, $projectId);

    // Notify Student
    $notifTitle = "🎓 Defense Result Released: " . str_replace('_', ' ', strtoupper($outcome));
    $notifMsg   = "Your defense examination result has been recorded. Outcome: " . str_replace('_', ' ', ucfirst($outcome)) . ".";
    createNotification($pdo, $studentId, 'defense_outcome', $notifTitle, $notifMsg, "../projects/view.php?id={$projectId}");

    // Audit log
    log_audit($pdo, 'defense_outcome_recorded', 'defense_outcome', $outcomeId, "Defense outcome recorded for project #{$projectId}: {$outcome}.");

    return ['success' => true, 'outcome_id' => $outcomeId];
}

/**
 * Grant final institutional approval and mark research project as COMPLETED.
 */
function grantFinalProjectApproval(PDO $pdo, int $projectId, int $approvedBy, ?string $remarks = null): array {
    if ($projectId <= 0 || $approvedBy <= 0) {
        return ['success' => false, 'error' => 'Invalid project or user identifier.'];
    }

    $pStmt = $pdo->prepare("SELECT id, owner_id, title, status FROM research_projects WHERE id = :id LIMIT 1");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        return ['success' => false, 'error' => 'Research project not found.'];
    }

    $studentId = (int)$project['owner_id'];

    // Verify post-defense corrections accepted if applicable
    $corrStmt = $pdo->prepare("SELECT COUNT(*) FROM project_corrections WHERE project_id = :pid AND status != 'accepted'");
    $corrStmt->execute([':pid' => $projectId]);
    $unresolvedCount = (int)$corrStmt->fetchColumn();

    if ($unresolvedCount > 0) {
        return ['success' => false, 'error' => "Cannot issue final completion approval: {$unresolvedCount} correction item(s) remain unverified/unaccepted."];
    }

    // Update project status to COMPLETED and APPROVED
    $upd = $pdo->prepare("UPDATE research_projects SET status = 'completed', updated_at = NOW() WHERE id = :id");
    $upd->execute([':id' => $projectId]);

    // Ensure all 9 project milestones are marked completed
    $updM = $pdo->prepare("UPDATE project_milestones SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE project_id = :pid");
    $updM->execute([':pid' => $projectId]);

    // Audit log
    log_audit($pdo, 'final_project_approved', 'research_project', $projectId, "Final institutional project approval granted by User #{$approvedBy}. Remarks: {$remarks}");

    // Notification to Student
    createNotification(
        $pdo, 
        $studentId, 
        'project_completed', 
        '🎉 Final Project Completed & Approved!', 
        "Congratulations! Your research project '{$project['title']}' has received final institutional approval and is marked as COMPLETED.", 
        "../projects/view.php?id={$projectId}"
    );

    return ['success' => true];
}
