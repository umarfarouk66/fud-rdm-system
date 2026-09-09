<?php
/**
 * Repository Integration & Final Project Publication Helpers
 * FUD RDM System - Phase 9: Repository Integration & Final Project Publication
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../supervision/supervision_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../datasets/citation_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';

/**
 * Perform server-side repository eligibility check for a research project.
 */
function checkRepositoryEligibility(PDO $pdo, int $projectId): array {
    $eligibility = [
        'is_eligible'    => false,
        'eligible_count' => 0,
        'total_count'    => 6,
        'checklist'      => []
    ];

    if ($projectId <= 0) {
        return $eligibility;
    }

    $pStmt = $pdo->prepare("
        SELECT rp.*, u.first_name AS owner_first, u.last_name AS owner_last, u.email AS owner_email
        FROM research_projects rp
        INNER JOIN users u ON rp.owner_id = u.id
        WHERE rp.id = :id LIMIT 1
    ");
    $pStmt->execute([':id' => $projectId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        return $eligibility;
    }

    $pStatus = strtolower($project['status']);

    // 1. Academic Readiness & Chapter Approvals
    $readiness = checkFinalProjectReadiness($pdo, $projectId);
    $readinessPassed = $readiness['is_ready'];
    $eligibility['checklist'][] = [
        'key'     => 'academic_readiness',
        'label'   => 'Academic Chapters & Milestones Readiness',
        'passed'  => $readinessPassed,
        'details' => $readinessPassed ? 'All 5 chapters, topics & milestones approved' : "Unfulfilled prerequisite academic milestones"
    ];

    // 2. Supervisor Final Defense Approval
    $supApproved = in_array($pStatus, [
        'final_submission_approved', 'defense_scheduled', 'defense_completed', 'final_corrections', 'approved', 'completed'
    ], true);
    $eligibility['checklist'][] = [
        'key'     => 'supervisor_defense_approval',
        'label'   => 'Supervisor Defense Authorization',
        'passed'  => $supApproved,
        'details' => $supApproved ? 'Approved for oral defense by supervisor' : 'Awaiting supervisor defense authorization'
    ];

    // 3. Defense (Viva Voce) Examination Completed
    $defense = getProjectDefense($pdo, $projectId);
    $defenseCompleted = ($defense && strtolower($defense['status']) === 'completed') || in_array($pStatus, ['defense_completed', 'final_corrections', 'approved', 'completed'], true);
    $eligibility['checklist'][] = [
        'key'     => 'defense_completed',
        'label'   => 'Oral Defense (Viva Voce) Examination',
        'passed'  => $defenseCompleted,
        'details' => $defenseCompleted ? 'Oral defense examination completed' : 'Awaiting oral defense examination'
    ];

    // 4. Official Defense Outcome Recorded
    $outcome = $defense ? getDefenseOutcome($pdo, (int)$defense['id']) : null;
    $outcomeRecorded = ($outcome !== null) || in_array($pStatus, ['defense_completed', 'final_corrections', 'approved', 'completed'], true);
    $eligibility['checklist'][] = [
        'key'     => 'outcome_recorded',
        'label'   => 'Official Defense Outcome Released',
        'passed'  => $outcomeRecorded,
        'details' => $outcomeRecorded ? ('Outcome: ' . str_replace('_', ' ', strtoupper($outcome['outcome'] ?? 'Passed'))) : 'Awaiting defense panel result recording'
    ];

    // 5. Post-Defense Panel Corrections Accepted
    $corrStmt = $pdo->prepare("SELECT COUNT(*) FROM project_corrections WHERE project_id = :pid AND status != 'accepted'");
    $corrStmt->execute([':pid' => $projectId]);
    $openCorrCount = (int)$corrStmt->fetchColumn();
    $correctionsResolved = ($openCorrCount === 0);
    $eligibility['checklist'][] = [
        'key'     => 'post_defense_corrections',
        'label'   => 'Post-Defense Panel Corrections Resolved',
        'passed'  => $correctionsResolved,
        'details' => $correctionsResolved ? 'All requested corrections verified & accepted' : "{$openCorrCount} post-defense correction item(s) pending"
    ];

    // 6. Final Institutional Completion Sign-off
    $finalCompleted = ($pStatus === 'completed' || $pStatus === 'approved');
    $eligibility['checklist'][] = [
        'key'     => 'final_institutional_approval',
        'label'   => 'Final Institutional Project Sign-off',
        'passed'  => $finalCompleted,
        'details' => $finalCompleted ? 'Granted final institutional completion status' : 'Pending final institutional sign-off'
    ];

    // Calculate total passed
    $passed = 0;
    foreach ($eligibility['checklist'] as $c) {
        if ($c['passed']) $passed++;
    }

    $eligibility['eligible_count'] = $passed;
    $eligibility['is_eligible']    = ($passed === $eligibility['total_count']);

    return $eligibility;
}

/**
 * Fetch existing repository dataset record associated with a project.
 */
function getProjectRepositoryRecord(PDO $pdo, int $projectId): ?array {
    if ($projectId <= 0) return null;

    $stmt = $pdo->prepare("
        SELECT d.*, u.first_name AS owner_first, u.last_name AS owner_last, u.email AS owner_email, u.department AS owner_dept, u.faculty AS owner_faculty,
               rp.project_code, rp.title AS project_title, rp.status AS project_status
        FROM datasets d
        INNER JOIN research_projects rp ON d.project_id = rp.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.project_id = :pid AND d.status != 'deleted'
        ORDER BY d.id DESC LIMIT 1
    ");
    $stmt->execute([':pid' => $projectId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        return null;
    }

    $datasetId = (int)$dataset['id'];

    // Fetch metadata
    $mStmt = $pdo->prepare("SELECT * FROM dataset_metadata WHERE dataset_id = :did LIMIT 1");
    $mStmt->execute([':did' => $datasetId]);
    $dataset['metadata'] = $mStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Fetch versions
    $vStmt = $pdo->prepare("SELECT * FROM dataset_versions WHERE dataset_id = :did ORDER BY version_number DESC");
    $vStmt->execute([':did' => $datasetId]);
    $dataset['versions'] = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch PIDs
    $dataset['identifiers'] = getDatasetIdentifiers($pdo, $datasetId);

    // Fetch pre-generated citations
    $dataset['citations'] = getOrGenerateDatasetCitations($pdo, $datasetId, $dataset, $dataset['metadata']);

    return $dataset;
}

/**
 * Student submits completed project for repository review.
 */
function submitProjectToRepository(PDO $pdo, int $projectId, array $data, int $studentId): array {
    if ($projectId <= 0 || $studentId <= 0) {
        return ['success' => false, 'error' => 'Invalid parameters specified.'];
    }

    // Verify server-side eligibility gate
    $eligibility = checkRepositoryEligibility($pdo, $projectId);
    if (!$eligibility['is_eligible']) {
        return ['success' => false, 'error' => 'Cannot submit to repository: Academic workflow prerequisites are unfulfilled.'];
    }

    // Fetch project details
    $pStmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :id AND owner_id = :uid LIMIT 1");
    $pStmt->execute([':id' => $projectId, ':uid' => $studentId]);
    $project = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        return ['success' => false, 'error' => 'Research project record not found or access denied.'];
    }

    $title       = trim($data['title'] ?? $project['title']);
    $description = trim($data['description'] ?? $project['description']);
    $keywords    = trim($data['keywords'] ?? '');
    $subjectArea = trim($data['subject_area'] ?? $project['research_area']);
    $accessLevel = trim($data['access_level'] ?? 'public'); // 'public', 'restricted', 'private'
    $datasetType = trim($data['dataset_type'] ?? 'Thesis / Project Document Package');
    $license     = trim($data['license'] ?? 'CC BY 4.0 International');

    if (!in_array($accessLevel, ['public', 'restricted', 'private'], true)) {
        $accessLevel = 'public';
    }

    // Fetch final approved document from project_submissions
    $subStmt = $pdo->prepare("
        SELECT * FROM project_submissions 
        WHERE project_id = :pid AND status = 'approved'
        ORDER BY id DESC LIMIT 1
    ");
    $subStmt->execute([':pid' => $projectId]);
    $finalSubmission = $subStmt->fetch(PDO::FETCH_ASSOC);

    // Check existing repository record
    $existing = getProjectRepositoryRecord($pdo, $projectId);

    if ($existing) {
        $datasetId = (int)$existing['id'];
        $upd = $pdo->prepare("
            UPDATE datasets 
            SET title = :title,
                description = :desc,
                dataset_type = :type,
                access_level = :access,
                status = 'submitted',
                submitted_to_repository_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':title'  => $title,
            ':desc'   => $description,
            ':type'   => $datasetType,
            ':access' => $accessLevel,
            ':id'     => $datasetId
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO datasets (
                project_id, owner_id, title, description, dataset_type, 
                access_level, status, current_version, submitted_to_repository_at, created_at, updated_at
            ) VALUES (
                :pid, :uid, :title, :desc, :type,
                :access, 'submitted', 1, NOW(), NOW(), NOW()
            )
        ");
        $ins->execute([
            ':pid'    => $projectId,
            ':uid'    => $studentId,
            ':title'  => $title,
            ':desc'   => $description,
            ':type'   => $datasetType,
            ':access' => $accessLevel
        ]);
        $datasetId = (int)$pdo->lastInsertId();
    }

    // Upsert metadata record
    $mCheck = $pdo->prepare("SELECT id FROM dataset_metadata WHERE dataset_id = :did LIMIT 1");
    $mCheck->execute([':did' => $datasetId]);
    $mId = $mCheck->fetchColumn();

    $creatorName = trim($data['creator'] ?? ($project['owner_first_name'] . ' ' . $project['owner_last_name']));
    if (empty($creatorName)) {
        $stUser = currentUser();
        $creatorName = $stUser['first_name'] . ' ' . $stUser['last_name'];
    }

    if ($mId) {
        $updM = $pdo->prepare("
            UPDATE dataset_metadata 
            SET creator = :creator,
                keywords = :keywords,
                subject_area = :subject,
                creation_date = NOW(),
                modification_date = NOW(),
                license = :license,
                updated_at = NOW()
            WHERE id = :mid
        ");
        $updM->execute([
            ':creator'  => $creatorName,
            ':keywords' => $keywords,
            ':subject'  => $subjectArea,
            ':license'  => $license,
            ':mid'      => $mId
        ]);
    } else {
        $insM = $pdo->prepare("
            INSERT INTO dataset_metadata (
                dataset_id, creator, keywords, subject_area, creation_date, modification_date, license, created_at, updated_at
            ) VALUES (
                :did, :creator, :keywords, :subject, NOW(), NOW(), :license, NOW(), NOW()
            )
        ");
        $insM->execute([
            ':did'      => $datasetId,
            ':creator'  => $creatorName,
            ':keywords' => $keywords,
            ':subject'  => $subjectArea,
            ':license'  => $license
        ]);
    }

    // Attach final document to dataset_versions if submission file exists
    if ($finalSubmission && !empty($finalSubmission['file_path'])) {
        $vCheck = $pdo->prepare("SELECT id FROM dataset_versions WHERE dataset_id = :did LIMIT 1");
        $vCheck->execute([':did' => $datasetId]);
        if (!$vCheck->fetch()) {
            $insV = $pdo->prepare("
                INSERT INTO dataset_versions (
                    dataset_id, version_number, file_name, stored_file_name, file_path, 
                    file_size, mime_type, checksum, uploaded_by, version_notes, created_at
                ) VALUES (
                    :did, 1, :fname, :sfname, :fpath,
                    :fsize, :mime, :checksum, :uid, 'Final Approved Academic Thesis Document', NOW()
                )
            ");
            $insV->execute([
                ':did'    => $datasetId,
                ':fname'  => $finalSubmission['file_name'] ?? 'final_project_document.pdf',
                ':sfname' => basename($finalSubmission['file_path']),
                ':fpath'  => $finalSubmission['file_path'],
                ':fsize'  => (int)($finalSubmission['file_size'] ?? 0),
                ':mime'   => $finalSubmission['mime_type'] ?? 'application/pdf',
                ':checksum' => $finalSubmission['checksum'] ?? hash('sha256', $finalSubmission['file_name'] ?? 'final'),
                ':uid'    => $studentId
            ]);
        }
    }

    // Send notifications to Librarians
    $libStmt = $pdo->query("SELECT u.id FROM users u INNER JOIN roles r ON u.role_id = r.id WHERE r.name = 'librarian' AND u.status = 'active'");
    $librarians = $libStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($librarians as $libId) {
        createNotification(
            $pdo, 
            (int)$libId, 
            'repository_submission', 
            '📚 New Repository Submission Pending Review', 
            "Student completed project '{$title}' submitted for repository metadata review & publication.", 
            "../librarian/repository_review.php?dataset_id={$datasetId}"
        );
    }

    // Audit log
    log_audit($pdo, 'repository_submitted', 'datasets', $datasetId, "Student User #{$studentId} submitted project '{$title}' to repository workflow.");

    return ['success' => true, 'dataset_id' => $datasetId];
}

/**
 * Librarian reviews, requests corrections, or publishes a repository submission.
 */
function reviewRepositorySubmission(PDO $pdo, int $datasetId, string $action, array $data, int $librarianId): array {
    if ($datasetId <= 0 || $librarianId <= 0) {
        return ['success' => false, 'error' => 'Invalid dataset or librarian identifier.'];
    }

    $dStmt = $pdo->prepare("
        SELECT d.*, rp.owner_id AS student_id, rp.title AS project_title, u.first_name AS st_first, u.last_name AS st_last
        FROM datasets d
        INNER JOIN research_projects rp ON d.project_id = rp.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.id = :id LIMIT 1
    ");
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        return ['success' => false, 'error' => 'Repository dataset record not found.'];
    }

    $studentId = (int)$dataset['student_id'];
    $notes     = trim($data['repository_notes'] ?? '');

    if ($action === 'request_corrections') {
        $upd = $pdo->prepare("
            UPDATE datasets 
            SET status = 'corrections_required',
                repository_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':notes' => $notes, ':id' => $datasetId]);

        // Audit log
        log_audit($pdo, 'repository_corrections_requested', 'datasets', $datasetId, "Librarian User #{$librarianId} requested repository metadata corrections.");

        // Notification to student
        createNotification(
            $pdo, 
            $studentId, 
            'repository_corrections', 
            '⚠️ Repository Corrections Requested', 
            "The University Librarian requested metadata corrections before publishing your project.", 
            "../projects/view.php?id={$dataset['project_id']}"
        );

        return ['success' => true, 'message' => 'Repository corrections requested from student.'];

    } elseif ($action === 'approve') {
        $upd = $pdo->prepare("
            UPDATE datasets 
            SET status = 'approved',
                repository_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':notes' => $notes, ':id' => $datasetId]);

        // Audit log
        log_audit($pdo, 'repository_submission_approved', 'datasets', $datasetId, "Librarian User #{$librarianId} approved repository metadata.");

        return ['success' => true, 'message' => 'Repository submission approved for publication.'];

    } elseif ($action === 'publish') {
        $accessLevel = trim($data['access_level'] ?? 'public');
        if (!in_array($accessLevel, ['public', 'restricted', 'private'], true)) {
            $accessLevel = 'public';
        }

        $pidType  = trim($data['pid_type'] ?? 'DOI');
        $pidValue = trim($data['pid_value'] ?? '');

        // Generate PID if empty
        if (empty($pidValue)) {
            if ($pidType === 'DOI') {
                $pidValue = "10.5061/fud.rdm." . date('Y') . "." . str_pad($datasetId, 5, '0', STR_PAD_LEFT);
            } elseif ($pidType === 'Handle') {
                $pidValue = "hdl:12345/fud-" . str_pad($datasetId, 6, '0', STR_PAD_LEFT);
            } elseif ($pidType === 'ARK') {
                $pidValue = "ark:/12345/fud" . str_pad($datasetId, 6, '0', STR_PAD_LEFT);
            } else {
                $pidValue = "FUD-RDM-PID-" . str_pad($datasetId, 6, '0', STR_PAD_LEFT);
            }
        }

        // Save PID
        setDatasetIdentifier($pdo, $datasetId, $pidType, $pidValue);

        // Update dataset to PUBLISHED
        $upd = $pdo->prepare("
            UPDATE datasets 
            SET status = 'published',
                access_level = :access,
                published_at = NOW(),
                repository_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':access' => $accessLevel,
            ':notes'  => $notes,
            ':id'     => $datasetId
        ]);

        // Execute digital preservation registration
        $presNote = encodePreservationNotes([
            'action'              => 'preserve',
            'location'            => 'Institutional Research Repository (Primary Tier)',
            'verification_status' => 'verified',
            'published_by'        => $librarianId,
            'timestamp'           => date('c')
        ]);

        $insP = $pdo->prepare("
            INSERT INTO preservation_records (dataset_id, action, notes, performed_by, performed_at)
            VALUES (:did, 'preserve', :notes, :uid, NOW())
        ");
        $insP->execute([
            ':did'   => $datasetId,
            ':notes' => $presNote,
            ':uid'   => $librarianId
        ]);

        // Audit log
        log_audit($pdo, 'repository_published', 'datasets', $datasetId, "Librarian User #{$librarianId} published repository dataset #{$datasetId} with {$pidType} '{$pidValue}'.");

        // Notify student & supervisor
        createNotification(
            $pdo, 
            $studentId, 
            'repository_published', 
            '🎉 Project Published in Institutional Repository!', 
            "Congratulations! Your research project '{$dataset['project_title']}' is now officially PUBLISHED in the FUD Repository (PID: {$pidValue}).", 
            "../datasets/view.php?id={$datasetId}"
        );

        return ['success' => true, 'message' => 'Project repository record officially published with PID & preservation registration!'];

    } elseif ($action === 'archive') {
        $upd = $pdo->prepare("
            UPDATE datasets 
            SET status = 'archived',
                archived_at = NOW(),
                repository_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':notes' => $notes, ':id' => $datasetId]);

        // Preservation record
        $insP = $pdo->prepare("
            INSERT INTO preservation_records (dataset_id, action, notes, performed_by, performed_at)
            VALUES (:did, 'archive', :notes, :uid, NOW())
        ");
        $insP->execute([
            ':did'   => $datasetId,
            ':notes' => $notes ?: 'Archived by Librarian',
            ':uid'   => $librarianId
        ]);

        // Audit log
        log_audit($pdo, 'repository_archived', 'datasets', $datasetId, "Librarian User #{$librarianId} archived repository record #{$datasetId}.");

        return ['success' => true, 'message' => 'Repository record archived successfully.'];
    }

    return ['success' => false, 'error' => 'Invalid repository review action specified.'];
}
