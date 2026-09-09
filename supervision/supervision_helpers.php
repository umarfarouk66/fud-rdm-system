<?php
/**
 * Supervision & Academic Project Workflow Helpers
 * RDM Information System - Phases 4, 5 & 6
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

/**
 * Fetch the configured chapter count (5 or 7) for a research project
 */
function getProjectChapterCount(PDO $pdo, int $projectId): int {
    if ($projectId <= 0) {
        return 5;
    }
    try {
        $stmt = $pdo->prepare("SELECT chapter_count FROM research_projects WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $projectId]);
        $val = (int)$stmt->fetchColumn();
        return ($val === 7) ? 7 : 5;
    } catch (Exception $e) {
        return 5;
    }
}

/**
 * Get definition dictionary for all academic document submission stages in sequential order
 */
function getChapterStageDefinitions(?int $chapterCount = 5): array {
    $count = ($chapterCount === 7) ? 7 : 5;

    if ($count === 7) {
        return [
            'chapter_1' => [
                'type'          => 'chapter_1',
                'label'         => 'Chapter 1: Introduction & Background',
                'short_name'    => 'Chapter 1',
                'short'         => 'Chapter 1',
                'prev_stage'    => 'proposal',
                'prev_label'    => 'Research Proposal',
                'status_prefix' => 'chapter_1'
            ],
            'chapter_2' => [
                'type'          => 'chapter_2',
                'label'         => 'Chapter 2: Literature Review & Theoretical Framework',
                'short_name'    => 'Chapter 2',
                'short'         => 'Chapter 2',
                'prev_stage'    => 'chapter_1',
                'prev_label'    => 'Chapter 1',
                'status_prefix' => 'chapter_2'
            ],
            'chapter_3' => [
                'type'          => 'chapter_3',
                'label'         => 'Chapter 3: System Analysis & Design / Methodology',
                'short_name'    => 'Chapter 3',
                'short'         => 'Chapter 3',
                'prev_stage'    => 'chapter_2',
                'prev_label'    => 'Chapter 2',
                'status_prefix' => 'chapter_3'
            ],
            'chapter_4' => [
                'type'          => 'chapter_4',
                'label'         => 'Chapter 4: Implementation & System Construction',
                'short_name'    => 'Chapter 4',
                'short'         => 'Chapter 4',
                'prev_stage'    => 'chapter_3',
                'prev_label'    => 'Chapter 3',
                'status_prefix' => 'chapter_4'
            ],
            'chapter_5' => [
                'type'          => 'chapter_5',
                'label'         => 'Chapter 5: Testing, Evaluation & Results',
                'short_name'    => 'Chapter 5',
                'short'         => 'Chapter 5',
                'prev_stage'    => 'chapter_4',
                'prev_label'    => 'Chapter 4',
                'status_prefix' => 'chapter_5'
            ],
            'chapter_6' => [
                'type'          => 'chapter_6',
                'label'         => 'Chapter 6: Findings & Discussion',
                'short_name'    => 'Chapter 6',
                'short'         => 'Chapter 6',
                'prev_stage'    => 'chapter_5',
                'prev_label'    => 'Chapter 5',
                'status_prefix' => 'chapter_6'
            ],
            'chapter_7' => [
                'type'          => 'chapter_7',
                'label'         => 'Chapter 7: Summary, Conclusion & Recommendations',
                'short_name'    => 'Chapter 7',
                'short'         => 'Chapter 7',
                'prev_stage'    => 'chapter_6',
                'prev_label'    => 'Chapter 6',
                'status_prefix' => 'chapter_7'
            ],
            'full_draft' => [
                'type'          => 'full_draft',
                'label'         => 'Full Dissertation / Thesis Draft',
                'short_name'    => 'Full Draft',
                'short'         => 'Full Draft',
                'prev_stage'    => 'chapter_7',
                'prev_label'    => 'Chapter 7',
                'status_prefix' => 'full_draft'
            ],
            'final_submission' => [
                'type'          => 'final_submission',
                'label'         => 'Final Approved Bound Research Project',
                'short_name'    => 'Final Submission',
                'short'         => 'Final Submission',
                'prev_stage'    => 'full_draft',
                'prev_label'    => 'Full Draft',
                'status_prefix' => 'final_submission'
            ]
        ];
    }

    return [
        'chapter_1' => [
            'type'          => 'chapter_1',
            'label'         => 'Chapter 1: Introduction & Background',
            'short_name'    => 'Chapter 1',
            'short'         => 'Chapter 1',
            'prev_stage'    => 'proposal',
            'prev_label'    => 'Research Proposal',
            'status_prefix' => 'chapter_1'
        ],
        'chapter_2' => [
            'type'          => 'chapter_2',
            'label'         => 'Chapter 2: Literature Review',
            'short_name'    => 'Chapter 2',
            'short'         => 'Chapter 2',
            'prev_stage'    => 'chapter_1',
            'prev_label'    => 'Chapter 1',
            'status_prefix' => 'chapter_2'
        ],
        'chapter_3' => [
            'type'          => 'chapter_3',
            'label'         => 'Chapter 3: Research Methodology',
            'short_name'    => 'Chapter 3',
            'short'         => 'Chapter 3',
            'prev_stage'    => 'chapter_2',
            'prev_label'    => 'Chapter 2',
            'status_prefix' => 'chapter_3'
        ],
        'chapter_4' => [
            'type'          => 'chapter_4',
            'label'         => 'Chapter 4: Results & Data Analysis',
            'short_name'    => 'Chapter 4',
            'short'         => 'Chapter 4',
            'prev_stage'    => 'chapter_3',
            'prev_label'    => 'Chapter 3',
            'status_prefix' => 'chapter_4'
        ],
        'chapter_5' => [
            'type'          => 'chapter_5',
            'label'         => 'Chapter 5: Discussion, Conclusion & Recommendations',
            'short_name'    => 'Chapter 5',
            'short'         => 'Chapter 5',
            'prev_stage'    => 'chapter_4',
            'prev_label'    => 'Chapter 4',
            'status_prefix' => 'chapter_5'
        ],
        'full_draft' => [
            'type'          => 'full_draft',
            'label'         => 'Full Dissertation / Thesis Draft',
            'short_name'    => 'Full Draft',
            'short'         => 'Full Draft',
            'prev_stage'    => 'chapter_5',
            'prev_label'    => 'Chapter 5',
            'status_prefix' => 'full_draft'
        ],
        'final_submission' => [
            'type'          => 'final_submission',
            'label'         => 'Final Approved Bound Research Project',
            'short_name'    => 'Final Submission',
            'short'         => 'Final Submission',
            'prev_stage'    => 'full_draft',
            'prev_label'    => 'Full Draft',
            'status_prefix' => 'final_submission'
        ]
    ];
}

/**
 * Check if a specific document submission stage is unlocked for a research project
 */
function isStageUnlocked(PDO $pdo, int $projectId, string $stageType, ?string $projectStatus = ''): bool {
    $chapterCount = getProjectChapterCount($pdo, $projectId);
    $stages = getChapterStageDefinitions($chapterCount);
    
    if (!isset($stages[$stageType])) {
        return false;
    }

    $projectStatus = strtolower(trim($projectStatus ?? ''));

    // If project is completed, approved, or in defense/final approval, all stages are unlocked for viewing
    if (in_array($projectStatus, ['completed', 'approved', 'final_submission_approved', 'defense_scheduled', 'defense_completed', 'final_corrections'], true)) {
        return true;
    }

    $prevStage = $stages[$stageType]['prev_stage'] ?? null;
    
    if (!$prevStage) {
        return true;
    }

    // Check if the previous stage document submission is approved
    if ($prevStage === 'proposal') {
        $approvedStatuses = [
            'proposal_approved', 'chapter_1_submitted', 'chapter_1_approved', 'chapter_2_submitted', 'chapter_2_approved',
            'chapter_3_submitted', 'chapter_3_approved', 'chapter_4_submitted', 'chapter_4_approved',
            'chapter_5_submitted', 'chapter_5_approved', 'chapter_6_submitted', 'chapter_6_approved',
            'chapter_7_submitted', 'chapter_7_approved', 'full_draft_submitted', 'full_draft_approved',
            'final_submission_submitted', 'final_submission_approved', 'in_progress', 'defense_scheduled',
            'defense_completed', 'final_corrections', 'approved', 'completed'
        ];
        if (in_array($projectStatus, $approvedStatuses, true)) {
            return true;
        }

        try {
            $stmt = $pdo->prepare("SELECT status FROM project_submissions WHERE project_id = :pid AND submission_type = 'proposal' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':pid' => $projectId]);
            $status = $stmt->fetchColumn();
            return ($status === 'approved');
        } catch (Exception $e) {
            return false;
        }
    } else {
        try {
            $stmt = $pdo->prepare("SELECT status FROM project_submissions WHERE project_id = :pid AND submission_type = :stype ORDER BY id DESC LIMIT 1");
            $stmt->execute([':pid' => $projectId, ':stype' => $prevStage]);
            $status = $stmt->fetchColumn();

            if ($status === 'approved') {
                return true;
            }

            if ($chapterCount === 7) {
                $stageOrder = ['chapter_1' => 1, 'chapter_2' => 2, 'chapter_3' => 3, 'chapter_4' => 4, 'chapter_5' => 5, 'chapter_6' => 6, 'chapter_7' => 7, 'full_draft' => 8, 'final_submission' => 9];
            } else {
                $stageOrder = ['chapter_1' => 1, 'chapter_2' => 2, 'chapter_3' => 3, 'chapter_4' => 4, 'chapter_5' => 5, 'full_draft' => 6, 'final_submission' => 7];
            }
            
            $targetOrder = $stageOrder[$stageType] ?? 0;

            foreach ($stageOrder as $sKey => $sVal) {
                if ($sVal >= $targetOrder) {
                    if (strpos($projectStatus, $sKey) !== false) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Get active supervisor details for a given student ID
 */
function getStudentActiveSupervisor(PDO $pdo, int $studentId): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ss.id AS assignment_id,
                ss.supervisor_id,
                ss.assigned_at,
                ss.status AS assignment_status,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                sp.staff_id,
                d.name AS department_name,
                f.name AS faculty_name
            FROM student_supervisors ss
            INNER JOIN users u ON ss.supervisor_id = u.id
            LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
            LEFT JOIN departments d ON sp.department_id = d.id
            LEFT JOIN faculties f ON d.faculty_id = f.id
            WHERE ss.student_id = :student_id AND ss.status = 'active'
            ORDER BY ss.assigned_at DESC
            LIMIT 1
        ");
        $stmt->execute([':student_id' => $studentId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    } catch (PDOException $e) {
        error_log("getStudentActiveSupervisor Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if a user is the active assigned supervisor for a student
 */
function isSupervisorOfStudent(PDO $pdo, int $supervisorId, int $studentId): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM student_supervisors 
            WHERE supervisor_id = :supervisor_id 
              AND student_id = :student_id 
              AND status = 'active'
        ");
        $stmt->execute([
            ':supervisor_id' => $supervisorId,
            ':student_id'    => $studentId
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("isSupervisorOfStudent Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize default 9 research project milestones for a project if not already existing
 */
function ensureDefaultProjectMilestones(PDO $pdo, int $projectId): void {
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM project_milestones WHERE project_id = :p_id");
        $countStmt->execute([':p_id' => $projectId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            return; // Milestones already initialized
        }

        $defaultMilestones = [
            1 => ['title' => 'Topic Approval', 'desc' => 'Formulate research problem statement, scope and obtain supervisor topic approval.'],
            2 => ['title' => 'Proposal Approval', 'desc' => 'Author compliance-ready research proposal, methodology framework and clear supervisor defense.'],
            3 => ['title' => 'Chapter 1: Introduction & Background', 'desc' => 'Complete research background, statement of problem, objectives and significance.'],
            4 => ['title' => 'Chapter 2: Literature Review', 'desc' => 'Comprehensive review of related literature, theoretical framework and empirical studies.'],
            5 => ['title' => 'Chapter 3: Research Methodology', 'desc' => 'Detailed research design, sampling methods, data collection tools and analytical protocols.'],
            6 => ['title' => 'Chapter 4: Results & Data Analysis', 'desc' => 'Empirical data processing, statistical visualization and key findings analysis.'],
            7 => ['title' => 'Chapter 5: Discussion & Conclusion', 'desc' => 'Interpret findings, discuss implications, draw conclusions and provide policy recommendations.'],
            8 => ['title' => 'Full Thesis / Dissertation Draft', 'desc' => 'Compile complete integrated research manuscript for comprehensive supervisor review.'],
            9 => ['title' => 'Final Bound Research Submission', 'desc' => 'Incorporate final corrections and submit official institutional repository manuscript.']
        ];

        $insStmt = $pdo->prepare("
            INSERT INTO project_milestones (
                project_id, title, description, milestone_order, status
            ) VALUES (
                :p_id, :title, :desc, :order, 'pending'
            )
        ");

        foreach ($defaultMilestones as $order => $m) {
            $insStmt->execute([
                ':p_id'  => $projectId,
                ':title' => $m['title'],
                ':desc'  => $m['desc'],
                ':order' => $order
            ]);
        }
    } catch (PDOException $e) {
        error_log("ensureDefaultProjectMilestones Error: " . $e->getMessage());
    }
}

/**
 * Sync project milestone statuses automatically based on stage approvals and due dates
 */
function syncProjectMilestoneStatuses(PDO $pdo, int $projectId): void {
    try {
        ensureDefaultProjectMilestones($pdo, $projectId);

        // Fetch project status and submissions
        $pStmt = $pdo->prepare("SELECT status FROM research_projects WHERE id = :id LIMIT 1");
        $pStmt->execute([':id' => $projectId]);
        $projectStatus = $pStmt->fetchColumn();

        $subStmt = $pdo->prepare("SELECT submission_type, status FROM project_submissions WHERE project_id = :id");
        $subStmt->execute([':id' => $projectId]);
        $submissions = $subStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $topStmt = $pdo->prepare("SELECT status FROM project_topics WHERE project_id = :id ORDER BY id DESC LIMIT 1");
        $topStmt->execute([':id' => $projectId]);
        $topicStatus = $topStmt->fetchColumn();

        $milestonesStmt = $pdo->prepare("SELECT * FROM project_milestones WHERE project_id = :id ORDER BY milestone_order ASC");
        $milestonesStmt->execute([':id' => $projectId]);
        $milestones = $milestonesStmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');

        foreach ($milestones as $m) {
            $order = (int)$m['milestone_order'];
            $newStatus = $m['status'];
            $isApproved = false;
            $isInProgress = false;

            switch ($order) {
                case 1: // Topic
                    $isApproved = ($topicStatus === 'approved' || in_array($projectStatus, ['topic_approved', 'proposal_submitted', 'proposal_corrections', 'proposal_approved'], true));
                    $isInProgress = ($topicStatus === 'submitted' || $topicStatus === 'corrections_required');
                    break;
                case 2: // Proposal
                    $isApproved = (($submissions['proposal'] ?? '') === 'approved' || in_array($projectStatus, ['proposal_approved', 'chapter_1_submitted'], true));
                    $isInProgress = (($submissions['proposal'] ?? '') === 'submitted' || ($submissions['proposal'] ?? '') === 'corrections_required');
                    break;
                case 3: // Ch 1
                    $isApproved = (($submissions['chapter_1'] ?? '') === 'approved' || strpos($projectStatus, 'chapter_1_approved') === 0);
                    $isInProgress = (($submissions['chapter_1'] ?? '') === 'submitted' || ($submissions['chapter_1'] ?? '') === 'corrections_required');
                    break;
                case 4: // Ch 2
                    $isApproved = (($submissions['chapter_2'] ?? '') === 'approved' || strpos($projectStatus, 'chapter_2_approved') === 0);
                    $isInProgress = (($submissions['chapter_2'] ?? '') === 'submitted' || ($submissions['chapter_2'] ?? '') === 'corrections_required');
                    break;
                case 5: // Ch 3
                    $isApproved = (($submissions['chapter_3'] ?? '') === 'approved' || strpos($projectStatus, 'chapter_3_approved') === 0);
                    $isInProgress = (($submissions['chapter_3'] ?? '') === 'submitted' || ($submissions['chapter_3'] ?? '') === 'corrections_required');
                    break;
                case 6: // Ch 4
                    $isApproved = (($submissions['chapter_4'] ?? '') === 'approved' || strpos($projectStatus, 'chapter_4_approved') === 0);
                    $isInProgress = (($submissions['chapter_4'] ?? '') === 'submitted' || ($submissions['chapter_4'] ?? '') === 'corrections_required');
                    break;
                case 7: // Ch 5
                    $isApproved = (($submissions['chapter_5'] ?? '') === 'approved' || strpos($projectStatus, 'chapter_5_approved') === 0);
                    $isInProgress = (($submissions['chapter_5'] ?? '') === 'submitted' || ($submissions['chapter_5'] ?? '') === 'corrections_required');
                    break;
                case 8: // Full Draft
                    $isApproved = (($submissions['full_draft'] ?? '') === 'approved' || strpos($projectStatus, 'full_draft_approved') === 0);
                    $isInProgress = (($submissions['full_draft'] ?? '') === 'submitted' || ($submissions['full_draft'] ?? '') === 'corrections_required');
                    break;
                case 9: // Final Submission
                    $isApproved = (($submissions['final_submission'] ?? '') === 'approved' || $projectStatus === 'completed' || $projectStatus === 'approved');
                    $isInProgress = (($submissions['final_submission'] ?? '') === 'submitted' || ($submissions['final_submission'] ?? '') === 'corrections_required');
                    break;
            }

            if ($isApproved) {
                $newStatus = 'completed';
            } elseif ($isInProgress) {
                $newStatus = 'in_progress';
            } elseif ($m['due_date'] && $m['due_date'] < $today && $m['status'] !== 'completed') {
                $newStatus = 'overdue';
            }

            if ($newStatus !== $m['status']) {
                $upStmt = $pdo->prepare("
                    UPDATE project_milestones 
                    SET status = :status,
                        completed_at = CASE WHEN :status = 'completed' THEN CURRENT_TIMESTAMP ELSE completed_at END
                    WHERE id = :id
                ");
                $upStmt->execute([':status' => $newStatus, ':id' => $m['id']]);
            }
        }
    } catch (PDOException $e) {
        error_log("syncProjectMilestoneStatuses Error: " . $e->getMessage());
    }
}

/**
 * Calculate overall project progress percentage based on 9 workflow stages
 */
function calculateProjectProgressPercentage(PDO $pdo, int $projectId): array {
    try {
        syncProjectMilestoneStatuses($pdo, $projectId);

        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
            FROM project_milestones
            WHERE project_id = :p_id
        ");
        $stmt->execute([':p_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $completed = (int)($row['completed_count'] ?? 0);
        $total     = max(1, (int)($row['total_count'] ?? 9));

        // 9 stages: 11.11% per stage
        $percentage = (int)round(($completed / $total) * 100);

        return [
            'percentage'      => min(100, $percentage),
            'completed_count' => $completed,
            'total_count'     => $total
        ];
    } catch (PDOException $e) {
        error_log("calculateProjectProgressPercentage Error: " . $e->getMessage());
        return ['percentage' => 0, 'completed_count' => 0, 'total_count' => 9];
    }
}

/**
 * Fetch correction tracking items for a project
 */
function getProjectCorrections(PDO $pdo, int $projectId, ?string $statusFilter = null): array {
    try {
        $sql = "
            SELECT c.*, u.first_name AS req_first, u.last_name AS req_last
            FROM project_corrections c
            INNER JOIN users u ON c.requested_by = u.id
            WHERE c.project_id = :p_id
        ";
        $params = [':p_id' => $projectId];

        if ($statusFilter) {
            $sql .= " AND c.status = :status";
            $params[':status'] = $statusFilter;
        }

        $sql .= " ORDER BY c.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("getProjectCorrections Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Fetch overview of all assigned students for a supervisor with progress metrics
 */
function getSupervisorStudentsOverview(PDO $pdo, int $supervisorId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ss.id AS assignment_id,
                ss.assigned_at,
                ss.status AS supervision_status,
                u.id AS student_id,
                u.first_name,
                u.last_name,
                u.email,
                u.matric_number,
                u.academic_level,
                d.name AS dept_name,
                f.name AS faculty_name,
                pr.name AS prog_name,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                p.status AS project_status,
                p.updated_at AS last_activity
            FROM student_supervisors ss
            INNER JOIN users u ON ss.student_id = u.id
            LEFT JOIN departments d ON u.department_id = d.id
            LEFT JOIN faculties f ON d.faculty_id = f.id
            LEFT JOIN programmes pr ON u.programme_id = pr.id
            LEFT JOIN research_projects p ON p.owner_id = u.id
            WHERE ss.supervisor_id = :sup_id AND ss.status = 'active'
            ORDER BY ss.assigned_at DESC
        ");
        $stmt->execute([':sup_id' => $supervisorId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pendingReviews = [];
        $openCorrections = [];
        $overdueMilestones = [];

        foreach ($students as &$s) {
            if (!empty($s['project_id'])) {
                $pId = (int)$s['project_id'];
                $s['progress'] = calculateProjectProgressPercentage($pdo, $pId);
                $s['open_corrections'] = count(getProjectCorrections($pdo, $pId, 'open'));

                // Check pending review
                $st = $s['project_status'];
                $s['pending_review'] = (strpos($st, '_submitted') !== false || $st === 'topic_submitted' || $st === 'proposal_submitted');

                // Check overdue milestones
                $ovStmt = $pdo->prepare("SELECT COUNT(*) FROM project_milestones WHERE project_id = :p_id AND status = 'overdue'");
                $ovStmt->execute([':p_id' => $pId]);
                $s['overdue_milestones'] = (int)$ovStmt->fetchColumn();

                // Stalled flag (>30 days since last activity)
                $daysInactive = $s['last_activity'] ? (int)floor((time() - strtotime($s['last_activity'])) / 86400) : 999;
                $s['is_stalled'] = ($daysInactive > 30);
                $s['days_inactive'] = $daysInactive;

                if (!empty($s['pending_review'])) {
                    $pendingReviews[] = $s;
                }
                if (!empty($s['open_corrections'])) {
                    $openCorrections[] = $s;
                }
                if (!empty($s['overdue_milestones'])) {
                    $ovListStmt = $pdo->prepare("
                        SELECT pm.*, pm.title AS milestone_name, u.first_name, u.last_name, u.id AS student_id
                        FROM project_milestones pm
                        INNER JOIN research_projects rp ON pm.project_id = rp.id
                        INNER JOIN users u ON rp.owner_id = u.id
                        WHERE pm.project_id = :pid AND pm.status = 'overdue'
                    ");
                    $ovListStmt->execute([':pid' => $pId]);
                    foreach ($ovListStmt->fetchAll(PDO::FETCH_ASSOC) as $ovm) {
                        $overdueMilestones[] = $ovm;
                    }
                }
            } else {
                $s['progress'] = ['percentage' => 0, 'completed_count' => 0, 'total_count' => 9];
                $s['open_corrections'] = 0;
                $s['pending_review'] = false;
                $s['overdue_milestones'] = 0;
                $s['is_stalled'] = false;
                $s['days_inactive'] = 0;
            }
        }

        return [
            'students'  => $students,
            'attention' => [
                'pending_reviews'    => $pendingReviews,
                'open_corrections'   => $openCorrections,
                'overdue_milestones' => $overdueMilestones
            ]
        ];
    } catch (PDOException $e) {
        error_log("getSupervisorStudentsOverview Error: " . $e->getMessage());
        return [
            'students'  => [],
            'attention' => [
                'pending_reviews'    => [],
                'open_corrections'   => [],
                'overdue_milestones' => []
            ]
        ];
    }
}

/**
 * Fetch full supervision data for a research project including milestones and corrections
 */
function getProjectSupervisionData(PDO $pdo, int $projectId): array {
    $data = [
        'project'          => null,
        'topic'            => null,
        'proposal'         => null,
        'proposal_versions'=> [],
        'topic_reviews'    => [],
        'proposal_reviews' => [],
        'chapters'         => [],
        'supervisor'       => null,
        'progress_stages'  => [],
        'progress_metrics' => ['percentage' => 0, 'completed_count' => 0, 'total_count' => 9],
        'milestones'       => [],
        'corrections'      => []
    ];

    try {
        // Fetch project owner ID
        $pStmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :id LIMIT 1");
        $pStmt->execute([':id' => $projectId]);
        $projRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        $data['project'] = $projRow ?: null;
        $ownerId = $projRow ? (int)$projRow['owner_id'] : 0;
        $projectStatus = $projRow ? $projRow['status'] : 'planning';

        if ($ownerId > 0) {
            $data['supervisor'] = getStudentActiveSupervisor($pdo, $ownerId);
        }

        // 1. Fetch latest topic
        $tStmt = $pdo->prepare("
            SELECT t.*, u.first_name AS rev_first, u.last_name AS rev_last
            FROM project_topics t
            LEFT JOIN users u ON t.reviewed_by = u.id
            WHERE t.project_id = :project_id
            ORDER BY t.created_at DESC
            LIMIT 1
        ");
        $tStmt->execute([':project_id' => $projectId]);
        $data['topic'] = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 2. Fetch proposal submission
        $subStmt = $pdo->prepare("
            SELECT * FROM project_submissions 
            WHERE project_id = :project_id AND submission_type = 'proposal'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $subStmt->execute([':project_id' => $projectId]);
        $proposalSub = $subStmt->fetch(PDO::FETCH_ASSOC);
        $data['proposal'] = $proposalSub ?: null;

        if ($proposalSub) {
            $vStmt = $pdo->prepare("
                SELECT v.*, u.first_name AS uploader_first, u.last_name AS uploader_last
                FROM submission_versions v
                INNER JOIN users u ON v.uploaded_by = u.id
                WHERE v.submission_id = :sub_id
                ORDER BY v.version_number DESC
            ");
            $vStmt->execute([':sub_id' => $proposalSub['id']]);
            $data['proposal_versions'] = $vStmt->fetchAll(PDO::FETCH_ASSOC);

            $prStmt = $pdo->prepare("
                SELECT r.*, u.first_name AS rev_first, u.last_name AS rev_last
                FROM supervisor_reviews r
                INNER JOIN users u ON r.reviewer_id = u.id
                WHERE r.submission_id = :sub_id
                ORDER BY r.reviewed_at DESC
            ");
            $prStmt->execute([':sub_id' => $proposalSub['id']]);
            $data['proposal_reviews'] = $prStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3. Fetch chapter submissions
        $chapterCount = getProjectChapterCount($pdo, $projectId);
        $chapterDefs  = getChapterStageDefinitions($chapterCount);
        $data['chapter_count'] = $chapterCount;
        foreach ($chapterDefs as $typeKey => $def) {
            $cSubStmt = $pdo->prepare("
                SELECT * FROM project_submissions 
                WHERE project_id = :project_id AND submission_type = :stype
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $cSubStmt->execute([':project_id' => $projectId, ':stype' => $typeKey]);
            $cSub = $cSubStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $versions = [];
            $reviews  = [];

            if ($cSub) {
                $cVStmt = $pdo->prepare("
                    SELECT v.*, u.first_name AS uploader_first, u.last_name AS uploader_last
                    FROM submission_versions v
                    INNER JOIN users u ON v.uploaded_by = u.id
                    WHERE v.submission_id = :sub_id
                    ORDER BY v.version_number DESC
                ");
                $cVStmt->execute([':sub_id' => $cSub['id']]);
                $versions = $cVStmt->fetchAll(PDO::FETCH_ASSOC);

                $cRStmt = $pdo->prepare("
                    SELECT r.*, u.first_name AS rev_first, u.last_name AS rev_last
                    FROM supervisor_reviews r
                    INNER JOIN users u ON r.reviewer_id = u.id
                    WHERE r.submission_id = :sub_id
                    ORDER BY r.reviewed_at DESC
                ");
                $cRStmt->execute([':sub_id' => $cSub['id']]);
                $reviews = $cRStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $unlocked = isStageUnlocked($pdo, $projectId, $typeKey, $projectStatus);

            $data['chapters'][$typeKey] = [
                'definition' => $def,
                'submission' => $cSub,
                'versions'   => $versions,
                'reviews'    => $reviews,
                'unlocked'   => $unlocked
            ];
        }

        // 4. Milestones (Phase 6)
        syncProjectMilestoneStatuses($pdo, $projectId);
        $mStmt = $pdo->prepare("SELECT * FROM project_milestones WHERE project_id = :p_id ORDER BY milestone_order ASC");
        $mStmt->execute([':p_id' => $projectId]);
        $data['milestones'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. Corrections (Phase 6)
        $data['corrections'] = getProjectCorrections($pdo, $projectId);

        // 6. Progress metrics & stages
        $data['progress_metrics']    = calculateProjectProgressPercentage($pdo, $projectId);
        $data['progress_stages']     = calculateProjectProgressStages($data['topic'], $data['proposal'], $data['chapters'], $projectStatus);
        $data['progress_percentage'] = $data['progress_metrics']['percentage'] ?? 0;
        $data['current_stage_key']   = $data['progress_metrics']['current_stage'] ?? 'chapter_1';
        $data['current_stage_label'] = ucwords(str_replace('_', ' ', $projectStatus ?: 'Planning & Topic Selection'));

        // 7. Submissions overview
        $subOverviewStmt = $pdo->prepare("
            SELECT s.*, s.submission_type AS document_type,
                   (SELECT version_number FROM submission_versions WHERE submission_id = s.id ORDER BY version_number DESC LIMIT 1) AS version_number,
                   (SELECT created_at FROM submission_versions WHERE submission_id = s.id ORDER BY version_number DESC LIMIT 1) AS submitted_at
            FROM project_submissions s
            WHERE s.project_id = :p_id
            ORDER BY s.created_at DESC
        ");
        $subOverviewStmt->execute([':p_id' => $projectId]);
        $data['submissions'] = $subOverviewStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("getProjectSupervisionData Error: " . $e->getMessage());
    }

    return $data;
}

/**
 * Calculate sequential project progress stages for dashboard display
 */
function calculateProjectProgressStages(?array $topic, ?array $proposal, array $chapters, string $projectStatus): array {
    $stages = [];

    // Topic
    $topicState = 'locked';
    if ($topic) {
        $topicState = ($topic['status'] === 'approved') ? 'approved' : (($topic['status'] === 'corrections_required') ? 'corrections' : 'in_progress');
    }
    $stages[] = ['id' => 'topic', 'name' => 'Research Topic', 'state' => $topicState];

    // Proposal
    $propState = 'locked';
    if ($proposal) {
        $propState = ($proposal['status'] === 'approved') ? 'approved' : (($proposal['status'] === 'corrections_required') ? 'corrections' : 'in_progress');
    }
    $stages[] = ['id' => 'proposal', 'name' => 'Research Proposal', 'state' => $propState];

    // Chapters 1-5, Full Draft, Final
    foreach ($chapters as $key => $chData) {
        $chState = 'locked';
        if ($chData['submission']) {
            $st = $chData['submission']['status'];
            $chState = ($st === 'approved') ? 'approved' : (($st === 'corrections_required') ? 'corrections' : 'in_progress');
        }
        $stages[] = [
            'id'    => $key,
            'name'  => $chData['definition']['short_name'],
            'state' => $chState
        ];
    }

    return $stages;
}

/**
 * Format project supervision status badge HTML
 */
function formatSupervisionStatusBadge(string $status): string {
    $st = strtolower(trim($status));
    if (strpos($st, 'chapter_') === 0 || strpos($st, 'full_draft_') === 0 || strpos($st, 'final_submission_') === 0) {
        if (strpos($st, '_approved') !== false) {
            return '<span class="status-badge status-approved" style="background:#d1fae5; color:#065f46; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">' . e(str_replace('_', ' ', $st)) . '</span>';
        } elseif (strpos($st, '_corrections') !== false) {
            return '<span class="status-badge status-corrections" style="background:#fef3c7; color:#b45309; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">' . e(str_replace('_', ' ', $st)) . '</span>';
        } else {
            return '<span class="status-badge status-submitted" style="background:#dbeafe; color:#1e40af; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">' . e(str_replace('_', ' ', $st)) . '</span>';
        }
    }

    switch ($st) {
        case 'draft':
        case 'planning':
            return '<span class="status-badge status-draft" style="background:#f1f5f9; color:#475569; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Draft</span>';
        case 'topic_submitted':
        case 'topic_under_review':
            return '<span class="status-badge status-submitted" style="background:#e0f2fe; color:#0369a1; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Topic Submitted</span>';
        case 'topic_corrections':
            return '<span class="status-badge status-corrections" style="background:#fef3c7; color:#b45309; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Topic Corrections Required</span>';
        case 'topic_approved':
            return '<span class="status-badge status-approved" style="background:#dcfce7; color:#15803d; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Topic Approved</span>';
        case 'proposal_submitted':
        case 'proposal_under_review':
            return '<span class="status-badge status-submitted" style="background:#dbeafe; color:#1e40af; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Proposal Submitted</span>';
        case 'proposal_corrections':
            return '<span class="status-badge status-corrections" style="background:#ffedd5; color:#c2410c; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Proposal Corrections Required</span>';
        case 'proposal_approved':
            return '<span class="status-badge status-approved" style="background:#d1fae5; color:#065f46; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">Proposal Approved</span>';
        default:
            return '<span class="status-badge" style="background:#e2e8f0; color:#334155; padding:0.25rem 0.65rem; border-radius:9999px; font-weight:700; font-size:0.75rem; text-transform:uppercase;">' . e(str_replace('_', ' ', $status)) . '</span>';
    }
}
