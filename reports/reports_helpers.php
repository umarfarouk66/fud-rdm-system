<?php
/**
 * Reporting & Analytics Helpers
 * FUD RDM System - Phase 10: Institutional Reports & Analytics
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';

/**
 * Whitelist of allowed CSV export types
 */
define('REPORT_EXPORT_WHITELIST', [
    'projects'               => 'All Research Projects Report',
    'completed_projects'     => 'Completed Research Projects Report',
    'published_projects'     => 'Published Repository Projects Report',
    'archived_projects'      => 'Archived Research Projects Report',
    'projects_by_department' => 'Research Projects by Department Report',
    'projects_by_session'    => 'Research Projects by Academic Session Report',
    'datasets'               => 'Repository Collections & Datasets Report',
    'metadata_quality'       => 'Dataset Metadata Quality Report',
    'citation_pids'          => 'Citation & PID Allocation Report',
    'access'                 => 'Access Requests Governance Report',
    'preservation'           => 'Digital Preservation & Archival Quality Report',
    'activity'               => 'Repository Activity & Audit Log Report',
    'supervisors_workload'   => 'Supervisor Workload Analytics Report',
    'supervision_progress'   => 'Student Supervision Progress Report',
    'unassigned_students'    => 'Unassigned Active Students Report',
    'defense_viva'           => 'Oral Defense & Viva Examination Report',
    'repository_publication' => 'Repository Integration & Publication Pipeline Report'
]);

/**
 * Determine SQL scope filters and parameters based on user role
 */
function getReportingScope(int $userId, string $role): array {
    $role = strtolower(trim($role));
    $scope = [
        'dataset_where'     => "d.status != 'deleted'",
        'dataset_params'    => [],
        'project_where'     => "p.status != 'archived'",
        'project_params'    => [],
        'access_req_where'  => "1=1",
        'access_req_params' => [],
        'params'            => []
    ];

    if ($role === 'admin' || $role === 'librarian' || $role === 'super_admin') {
        // Admin, Super Admin and Librarian have institutional-wide reporting visibility
        return $scope;
    }

    if ($role === 'supervisor') {
        // Supervisor oversees assigned students' projects, related datasets and access requests
        $scope['project_where'] = "(p.owner_id IN (SELECT student_id FROM student_supervisors WHERE supervisor_id = :sup_uid1 AND status = 'active') OR p.owner_id = :sup_uid2)";
        $scope['project_params'] = [
            ':sup_uid1' => $userId,
            ':sup_uid2' => $userId
        ];

        $scope['dataset_where'] = "d.status != 'deleted' AND (d.owner_id IN (SELECT student_id FROM student_supervisors WHERE supervisor_id = :sup_d_uid1 AND status = 'active') OR d.owner_id = :sup_d_uid2)";
        $scope['dataset_params'] = [
            ':sup_d_uid1' => $userId,
            ':sup_d_uid2' => $userId
        ];

        $scope['access_req_where'] = "(ar.requester_id = :sup_a_uid1 OR ar.dataset_id IN (SELECT id FROM datasets WHERE owner_id = :sup_a_uid2 OR owner_id IN (SELECT student_id FROM student_supervisors WHERE supervisor_id = :sup_a_uid3 AND status = 'active')))";
        $scope['access_req_params'] = [
            ':sup_a_uid1' => $userId,
            ':sup_a_uid2' => $userId,
            ':sup_a_uid3' => $userId
        ];

        return $scope;
    }

    // Default: Researcher / Student role (strictly personal scope)
    $scope['project_where'] = "(p.owner_id = :res_p_uid1 OR p.id IN (SELECT project_id FROM project_members WHERE user_id = :res_p_uid2))";
    $scope['project_params'] = [
        ':res_p_uid1' => $userId,
        ':res_p_uid2' => $userId
    ];

    $scope['dataset_where'] = "d.status != 'deleted' AND (d.owner_id = :res_d_uid1 OR d.project_id IN (SELECT project_id FROM project_members WHERE user_id = :res_d_uid2))";
    $scope['dataset_params'] = [
        ':res_d_uid1' => $userId,
        ':res_d_uid2' => $userId
    ];

    $scope['access_req_where'] = "ar.requester_id = :res_a_uid1";
    $scope['access_req_params'] = [
        ':res_a_uid1' => $userId
    ];

    return $scope;
}

/**
 * Calculate institutional KPI metrics safely from database
 */
function getInstitutionalKpiMetrics(PDO $pdo, array $scope): array {
    $kpis = [
        'total_students'            => 0,
        'unassigned_students'       => 0,
        'total_supervisors'          => 0,
        'total_projects'             => 0,
        'active_projects'            => 0,
        'completed_projects'         => 0,
        'awaiting_supervisor_review' => 0,
        'open_corrections'           => 0,
        'approved_for_defense'       => 0,
        'scheduled_defenses'         => 0,
        'completed_defenses'         => 0,
        'awaiting_final_signoff'     => 0,
        'pending_repository_review'  => 0,
        'published_projects'         => 0,
        'archived_projects'          => 0
    ];

    try {
        // Students
        $st = $pdo->query("
            SELECT 
                COUNT(u.id) AS total,
                SUM(CASE WHEN ss.id IS NULL THEN 1 ELSE 0 END) AS unassigned
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id AND r.name = 'researcher'
            LEFT JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
            WHERE u.status = 'active'
        ")->fetch(PDO::FETCH_ASSOC);
        $kpis['total_students']      = (int)($st['total'] ?? 0);
        $kpis['unassigned_students'] = (int)($st['unassigned'] ?? 0);

        // Supervisors
        $kpis['total_supervisors'] = (int)$pdo->query("
            SELECT COUNT(u.id) FROM users u 
            INNER JOIN roles r ON u.role_id = r.id AND r.name = 'supervisor' 
            WHERE u.status = 'active'
        ")->fetchColumn();

        // Projects
        $pStmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status NOT IN ('completed', 'archived', 'cancelled') THEN 1 ELSE 0 END) AS active_cnt,
                SUM(CASE WHEN status IN ('completed', 'approved') THEN 1 ELSE 0 END) AS completed_cnt,
                SUM(CASE WHEN status = 'final_submission_approved' THEN 1 ELSE 0 END) AS ready_defense_cnt,
                SUM(CASE WHEN status = 'defense_scheduled' THEN 1 ELSE 0 END) AS sched_defense_cnt,
                SUM(CASE WHEN status = 'defense_completed' THEN 1 ELSE 0 END) AS done_defense_cnt,
                SUM(CASE WHEN status IN ('defense_completed', 'final_corrections') THEN 1 ELSE 0 END) AS signoff_cnt
            FROM research_projects p
            WHERE {$scope['project_where']}
        ");
        $pStmt->execute($scope['project_params']);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $kpis['total_projects']         = (int)($pRow['total'] ?? 0);
            $kpis['active_projects']        = (int)($pRow['active_cnt'] ?? 0);
            $kpis['completed_projects']     = (int)($pRow['completed_cnt'] ?? 0);
            $kpis['approved_for_defense']   = (int)($pRow['ready_defense_cnt'] ?? 0);
            $kpis['scheduled_defenses']     = (int)($pRow['sched_defense_cnt'] ?? 0);
            $kpis['completed_defenses']     = (int)($pRow['done_defense_cnt'] ?? 0);
            $kpis['awaiting_final_signoff'] = (int)($pRow['signoff_cnt'] ?? 0);
        }

        // Submissions awaiting supervisor review
        $kpis['awaiting_supervisor_review'] = (int)$pdo->query("SELECT COUNT(*) FROM project_submissions WHERE status IN ('submitted', 'under_review')")->fetchColumn();

        // Open corrections
        $kpis['open_corrections'] = (int)$pdo->query("SELECT COUNT(*) FROM project_corrections WHERE status IN ('open', 'addressed')")->fetchColumn();

        // Repository stats
        $dStmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS pending_rev,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS pub_cnt,
                SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS arch_cnt
            FROM datasets d
            WHERE {$scope['dataset_where']}
        ");
        $dStmt->execute($scope['dataset_params']);
        $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
        if ($dRow) {
            $kpis['pending_repository_review'] = (int)($dRow['pending_rev'] ?? 0);
            $kpis['published_projects']        = (int)($dRow['pub_cnt'] ?? 0);
            $kpis['archived_projects']         = (int)($dRow['arch_cnt'] ?? 0);
        }

    } catch (PDOException $e) {
        error_log("Institutional KPI Query Error: " . $e->getMessage());
    }

    return $kpis;
}

/**
 * Fetch Supervisor Workload Analytics
 */
function getSupervisorWorkloadAnalytics(PDO $pdo, array $filters = []): array {
    $where = "u.status = 'active'";
    $params = [];

    if (!empty($filters['department'])) {
        $where .= " AND u.department = :dept";
        $params[':dept'] = $filters['department'];
    }

    $sql = "
        SELECT 
            u.id AS supervisor_id,
            u.first_name, u.last_name, u.email, u.department, u.faculty,
            COUNT(DISTINCT ss.student_id) AS assigned_students_count,
            COUNT(DISTINCT CASE WHEN rp.status NOT IN ('completed', 'archived') THEN rp.id END) AS active_projects_count,
            COUNT(DISTINCT CASE WHEN rp.status = 'completed' THEN rp.id END) AS completed_projects_count,
            COUNT(DISTINCT CASE WHEN ps.status IN ('submitted', 'under_review') THEN ps.id END) AS pending_reviews_count,
            COUNT(DISTINCT CASE WHEN pc.status IN ('open', 'addressed') THEN pc.id END) AS open_corrections_count,
            COUNT(DISTINCT CASE WHEN pm.status != 'completed' AND pm.due_date IS NOT NULL AND pm.due_date < NOW() THEN pm.id END) AS overdue_milestones_count
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id AND r.name = 'supervisor'
        LEFT JOIN student_supervisors ss ON u.id = ss.supervisor_id AND ss.status = 'active'
        LEFT JOIN research_projects rp ON ss.student_id = rp.owner_id
        LEFT JOIN project_submissions ps ON rp.id = ps.project_id
        LEFT JOIN project_corrections pc ON rp.id = pc.project_id
        LEFT JOIN project_milestones pm ON rp.id = pm.project_id
        WHERE {$where}
        GROUP BY u.id, u.first_name, u.last_name, u.email, u.department, u.faculty
        ORDER BY assigned_students_count DESC, u.last_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch Unassigned Active Students Report
 */
function getUnassignedStudentsReport(PDO $pdo, array $filters = []): array {
    $where = "r.name = 'researcher' AND u.status = 'active' AND ss.id IS NULL";
    $params = [];

    if (!empty($filters['department'])) {
        $where .= " AND u.department = :dept";
        $params[':dept'] = $filters['department'];
    }

    $sql = "
        SELECT 
            u.id AS student_id, u.first_name, u.last_name, u.email, u.matric_number, u.department, u.faculty, u.academic_level, u.created_at AS date_registered,
            p.name AS programme_name,
            rp.id AS project_id, rp.title AS project_title, rp.project_code, rp.status AS project_status
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_supervisors ss ON u.id = ss.student_id AND ss.status = 'active'
        LEFT JOIN programmes p ON u.programme_id = p.id
        LEFT JOIN research_projects rp ON u.id = rp.owner_id AND rp.status != 'archived'
        WHERE {$where}
        ORDER BY u.created_at DESC, u.last_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build SQL date range clause safely with prepared parameters
 */
function buildDateFilterClause(string $preset, ?string $customStart, ?string $customEnd, string $column = 'created_at', string $paramPrefix = 'dt_'): array {
    $preset = strtolower(trim($preset));
    $clause = "";
    $params = [];

    switch ($preset) {
        case 'today':
            $clause = "DATE({$column}) = CURRENT_DATE()";
            break;
        case '7days':
            $clause = "{$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30days':
            $clause = "{$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case '90days':
            $clause = "{$column} >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
            break;
        case 'thisyear':
            $clause = "YEAR({$column}) = YEAR(CURRENT_DATE())";
            break;
        case 'custom':
            if (!empty($customStart) && !empty($customEnd)) {
                $clause = "DATE({$column}) BETWEEN :{$paramPrefix}start AND :{$paramPrefix}end";
                $params[":{$paramPrefix}start"] = $customStart;
                $params[":{$paramPrefix}end"]   = $customEnd;
            } elseif (!empty($customStart)) {
                $clause = "DATE({$column}) >= :{$paramPrefix}start";
                $params[":{$paramPrefix}start"] = $customStart;
            } elseif (!empty($customEnd)) {
                $clause = "DATE({$column}) <= :{$paramPrefix}end";
                $params[":{$paramPrefix}end"] = $customEnd;
            }
            break;
        case 'all':
        default:
            $clause = "1=1";
            break;
    }

    return [
        'clause' => $clause ?: "1=1",
        'params' => $params
    ];
}

/**
 * Safe rate calculation protected against division-by-zero
 */
function calculateSafeRate(int $numerator, int $denominator): float {
    if ($denominator <= 0) {
        return 0.0;
    }
    return round(($numerator / $denominator) * 100, 1);
}

/**
 * Sanitize cell values for CSV to prevent formula injection attacks
 */
function escapeCsvValue($value): string {
    if ($value === null) {
        return '';
    }
    $str = (string)$value;
    if (strlen($str) > 0 && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $str = "'" . $str;
    }
    return $str;
}



/**
 * Render modern CSS bar with percentage and count
 */
function renderBarVisual(string $label, int $count, int $total, string $color = '#2563eb'): string {
    $pct = calculateSafeRate($count, $total);
    return '
    <div style="margin-bottom: 0.85rem;">
        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.3rem;">
            <span>' . e($label) . '</span>
            <span style="color: var(--text-muted);">' . $count . ' (' . $pct . '%)</span>
        </div>
        <div style="height: 8px; background: #e2e8f0; border-radius: 9999px; overflow: hidden;">
            <div style="height: 100%; width: ' . $pct . '%; background: ' . e($color) . '; border-radius: 9999px;"></div>
        </div>
    </div>';
}

/**
 * Central Query Engine: Fetch filtered report dataset safely with prepared statements
 */
function getFilteredReportData(PDO $pdo, string $reportType, array $filters, array $scope): array {
    $reportType = strtolower(trim($reportType));
    if (!array_key_exists($reportType, REPORT_EXPORT_WHITELIST)) {
        $reportType = 'projects';
    }

    $dept       = trim($filters['department'] ?? '');
    $programme  = trim($filters['programme'] ?? '');
    $session    = trim($filters['session'] ?? '');
    $status     = trim($filters['status'] ?? '');
    $preset     = trim($filters['date_preset'] ?? 'all');
    $start      = trim($filters['start_date'] ?? '');
    $end        = trim($filters['end_date'] ?? '');
    $supId      = (int)($filters['supervisor_id'] ?? 0);

    $headers = [];
    $rows    = [];

    switch ($reportType) {
        case 'completed_projects':
        case 'published_projects':
        case 'archived_projects':
        case 'projects_by_department':
        case 'projects_by_programme':
        case 'projects_by_session':
        case 'projects':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'p.created_at', 'df_p_');
            $whereClause = "WHERE " . $scope['project_where'] . " AND " . $dateFilter['clause'];
            $params = array_merge($scope['project_params'], $dateFilter['params']);

            if ($reportType === 'completed_projects') {
                $whereClause .= " AND p.status IN ('completed', 'approved')";
            } elseif ($reportType === 'published_projects') {
                $whereClause .= " AND p.status IN ('completed', 'approved', 'final_submission_approved')";
            } elseif ($reportType === 'archived_projects') {
                $whereClause .= " AND p.status = 'archived'";
            }

            if (!empty($status) && $status !== 'all') {
                $whereClause .= " AND p.status = :f_status";
                $params[':f_status'] = $status;
            }

            if (!empty($dept)) {
                $whereClause .= " AND (p.department = :f_dept OR u.department = :f_dept)";
                $params[':f_dept'] = $dept;
            }

            if (!empty($programme)) {
                $whereClause .= " AND u.programme_id = :f_prog";
                $params[':f_prog'] = (int)$programme;
            }

            if (!empty($session)) {
                $whereClause .= " AND (p.created_at LIKE :f_sess OR p.start_date LIKE :f_sess)";
                $params[':f_sess'] = "%" . $session . "%";
            }

            if ($supId > 0) {
                $whereClause .= " AND p.owner_id IN (SELECT student_id FROM student_supervisors WHERE supervisor_id = :f_sup AND status = 'active')";
                $params[':f_sup'] = $supId;
            }

            $headers = ['ID', 'Project Code', 'Title', 'Owner / Researcher', 'Department', 'Faculty', 'Status', 'Start Date', 'Created At'];
            $stmt = $pdo->prepare("
                SELECT 
                    p.id, p.project_code, p.title, 
                    CONCAT(u.first_name, ' ', u.last_name) AS owner_name, 
                    COALESCE(p.department, u.department, 'N/A') AS dept, 
                    COALESCE(p.faculty, u.faculty, 'N/A') AS fac, 
                    p.status, p.start_date, p.created_at
                FROM research_projects p
                INNER JOIN users u ON p.owner_id = u.id
                {$whereClause}
                ORDER BY p.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'metadata_quality':
        case 'citation_pids':
        case 'datasets':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'd.created_at', 'df_d_');
            $whereClause = "WHERE " . $scope['dataset_where'] . " AND " . $dateFilter['clause'];
            $params = array_merge($scope['dataset_params'], $dateFilter['params']);

            if (!empty($status) && $status !== 'all') {
                $whereClause .= " AND d.status = :f_status";
                $params[':f_status'] = $status;
            }

            if (!empty($dept)) {
                $whereClause .= " AND (p.department = :f_dept OR u.department = :f_dept)";
                $params[':f_dept'] = $dept;
            }

            if ($reportType === 'metadata_quality') {
                $headers = ['Dataset ID', 'Dataset Title', 'Creator', 'Subject Area', 'Data Type', 'Access Level', 'Status', 'Created At'];
                $stmt = $pdo->prepare("
                    SELECT 
                        d.id, d.title AS dataset_title, dm.creator, dm.subject_area, dm.data_type, d.access_level, d.status, d.created_at
                    FROM datasets d
                    INNER JOIN research_projects p ON d.project_id = p.id
                    INNER JOIN users u ON d.owner_id = u.id
                    LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
                    {$whereClause}
                    ORDER BY d.id DESC
                ");
            } elseif ($reportType === 'citation_pids') {
                $headers = ['Dataset ID', 'Dataset Title', 'Project Code', 'Access Level', 'Status', 'PIDs / Identifiers', 'Created At'];
                $stmt = $pdo->prepare("
                    SELECT 
                        d.id, d.title AS dataset_title, p.project_code, d.access_level, d.status, d.created_at
                    FROM datasets d
                    INNER JOIN research_projects p ON d.project_id = p.id
                    INNER JOIN users u ON d.owner_id = u.id
                    {$whereClause}
                    ORDER BY d.id DESC
                ");
            } else { // datasets
                $headers = ['Dataset ID', 'Project Code', 'Project Title', 'Dataset Title', 'Access Level', 'Status', 'Version', 'Size (Bytes)', 'Downloads', 'Views', 'Creator', 'Subject Area', 'Data Type', 'Created At'];
                $stmt = $pdo->prepare("
                    SELECT 
                        d.id, p.project_code, p.title AS project_title, d.title AS dataset_title, d.access_level, d.status,
                        d.current_version, d.total_size, d.download_count, d.view_count,
                        dm.creator, dm.subject_area, dm.data_type, d.created_at
                    FROM datasets d
                    INNER JOIN research_projects p ON d.project_id = p.id
                    INNER JOIN users u ON d.owner_id = u.id
                    LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
                    {$whereClause}
                    ORDER BY d.id DESC
                ");
            }
            $stmt->execute($params);
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($reportType === 'citation_pids') {
                foreach ($rawRows as $r) {
                    $pids = getDatasetIdentifiers($pdo, (int)$r['id']);
                    $pidStr = implode(', ', array_map(fn($p) => $p['identifier_type'] . ':' . $p['identifier_value'], $pids));
                    $r['pids'] = $pidStr ?: 'DOI / Handle Pending';
                    $rows[] = $r;
                }
            } else {
                $rows = $rawRows;
            }
            break;

        case 'preservation':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'pr.performed_at', 'df_pr_');
            $whereClause = "WHERE " . $dateFilter['clause'];
            $params = $dateFilter['params'];

            if (!empty($dept)) {
                $whereClause .= " AND u.department = :f_dept";
                $params[':f_dept'] = $dept;
            }

            $headers = ['Record ID', 'Dataset ID', 'Dataset Title', 'Action Performed', 'Notes / Format', 'Performed By', 'Performed At'];
            $stmt = $pdo->prepare("
                SELECT 
                    pr.id, pr.dataset_id, d.title AS dataset_title, pr.action, COALESCE(pr.notes, 'Standard AIP/DIP') AS format,
                    CONCAT(u.first_name, ' ', u.last_name) AS staff_name, pr.performed_at
                FROM preservation_records pr
                INNER JOIN datasets d ON pr.dataset_id = d.id
                INNER JOIN users u ON pr.performed_by = u.id
                {$whereClause}
                ORDER BY pr.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'access':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'ar.created_at', 'df_ar_');
            $whereClause = "WHERE " . $scope['access_req_where'] . " AND " . $dateFilter['clause'];
            $params = array_merge($scope['access_req_params'], $dateFilter['params']);

            if (!empty($status) && $status !== 'all') {
                $whereClause .= " AND ar.status = :f_status";
                $params[':f_status'] = $status;
            }

            $headers = ['Request ID', 'Dataset ID', 'Dataset Title', 'Requester Name', 'Email', 'Purpose', 'Status', 'Requested At', 'Decided At'];
            $stmt = $pdo->prepare("
                SELECT 
                    ar.id, ar.dataset_id, d.title AS dataset_title,
                    CONCAT(u.first_name, ' ', u.last_name) AS requester_name, u.email,
                    COALESCE(ar.reason, 'Research Access') AS purpose, ar.status, ar.created_at, ar.updated_at
                FROM access_requests ar
                INNER JOIN datasets d ON ar.dataset_id = d.id
                INNER JOIN users u ON ar.requester_id = u.id
                {$whereClause}
                ORDER BY ar.id DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'activity':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'al.created_at', 'df_al_');
            $whereClause = "WHERE " . $dateFilter['clause'];
            $params = $dateFilter['params'];

            $headers = ['Log ID', 'User Name', 'Action', 'Entity Type', 'Entity ID', 'Details / Description', 'IP Address', 'Date Time'];
            $stmt = $pdo->prepare("
                SELECT 
                    al.id, CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                    al.action, al.entity_type, al.entity_id, al.description, al.ip_address, al.created_at
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                {$whereClause}
                ORDER BY al.id DESC
                LIMIT 500
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'supervisors_workload':
            $headers = ['Supervisor ID', 'Name', 'Email', 'Department', 'Faculty', 'Assigned Students', 'Active Projects', 'Completed Projects', 'Pending Reviews', 'Open Corrections', 'Overdue Milestones'];
            $workload = getSupervisorWorkloadAnalytics($pdo, ['department' => $dept]);
            foreach ($workload as $w) {
                $rows[] = [
                    $w['supervisor_id'],
                    $w['first_name'] . ' ' . $w['last_name'],
                    $w['email'],
                    $w['department'] ?: 'N/A',
                    $w['faculty'] ?: 'N/A',
                    $w['assigned_students_count'],
                    $w['active_projects_count'],
                    $w['completed_projects_count'],
                    $w['pending_reviews_count'],
                    $w['open_corrections_count'],
                    $w['overdue_milestones_count']
                ];
            }
            break;

        case 'unassigned_students':
            $headers = ['Student ID', 'Matric Number', 'Name', 'Email', 'Department', 'Faculty', 'Programme', 'Project Title', 'Date Registered'];
            $unassigned = getUnassignedStudentsReport($pdo, ['department' => $dept]);
            foreach ($unassigned as $u) {
                $rows[] = [
                    $u['student_id'],
                    $u['matric_number'] ?: 'N/A',
                    $u['first_name'] . ' ' . $u['last_name'],
                    $u['email'],
                    $u['department'] ?: 'N/A',
                    $u['faculty'] ?: 'N/A',
                    $u['programme_name'] ?: 'N/A',
                    $u['project_title'] ?: 'No Project',
                    $u['date_registered']
                ];
            }
            break;

        case 'defense_viva':
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'd.defense_date', 'df_def_');
            $whereClause = "WHERE " . $scope['project_where'] . " AND " . $dateFilter['clause'];
            $params = array_merge($scope['project_params'], $dateFilter['params']);

            $headers = ['Defense ID', 'Project ID', 'Project Title', 'Student Name', 'Matric Number', 'Defense Title', 'Date', 'Time', 'Venue', 'Status', 'Outcome'];
            $stmt = $pdo->prepare("
                SELECT 
                    d.id AS defense_id, d.project_id, p.title AS project_title, u.first_name, u.last_name, u.matric_number,
                    d.defense_title, d.defense_date, d.start_time, d.venue, d.status AS defense_status, o.outcome
                FROM project_defenses d
                INNER JOIN research_projects p ON d.project_id = p.id
                INNER JOIN users u ON p.owner_id = u.id
                LEFT JOIN defense_outcomes o ON d.id = o.defense_id
                {$whereClause}
                ORDER BY d.defense_date DESC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'repository_publication':
        default:
            $dateFilter = buildDateFilterClause($preset, $start, $end, 'd.created_at', 'df_rp_');
            $whereClause = "WHERE " . $scope['dataset_where'] . " AND " . $dateFilter['clause'];
            $params = array_merge($scope['dataset_params'], $dateFilter['params']);

            if (!empty($dept)) {
                $whereClause .= " AND (p.department = :f_dept OR u.department = :f_dept)";
                $params[':f_dept'] = $dept;
            }

            $headers = ['Dataset ID', 'Project Code', 'Project Title', 'Dataset Title', 'Access Level', 'Repository Status', 'Submitted At', 'Published At', 'PIDs'];
            $stmt = $pdo->prepare("
                SELECT 
                    d.id, p.project_code, p.title AS project_title, d.title AS dataset_title,
                    d.access_level, d.status, d.submitted_to_repository_at, d.published_at
                FROM datasets d
                INNER JOIN research_projects p ON d.project_id = p.id
                INNER JOIN users u ON d.owner_id = u.id
                {$whereClause}
                ORDER BY d.id DESC
            ");
            $stmt->execute($params);
            $rawDs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rawDs as $r) {
                $pids = getDatasetIdentifiers($pdo, (int)$r['id']);
                $pidStr = implode(', ', array_map(fn($p) => $p['identifier_type'] . ':' . $p['identifier_value'], $pids));
                $r['pids'] = $pidStr ?: 'None';
                $rows[] = $r;
            }
            break;
    }

    return [
        'report_type'  => $reportType,
        'report_title' => REPORT_EXPORT_WHITELIST[$reportType] ?? 'Institutional Report',
        'headers'      => $headers,
        'rows'         => $rows,
        'count'        => count($rows),
        'filters'      => $filters
    ];
}
