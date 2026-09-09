<?php
/**
 * Super Admin Monitoring & Governance Hub
 * FUD RDM System - Phase 11: Super Admin Monitoring & Governance
 * 
 * STRICTLY READ-ONLY GOVERNANCE ENVIRONMENT
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../reports/reports_helpers.php';
require_once __DIR__ . '/../defense/defense_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
require_once __DIR__ . '/admin_helpers.php';

// Require super_admin or admin role (Super Admin gets read-only monitoring view)
requireRole(['super_admin', 'admin']);

$currentUser = currentUser();
$currentUserId = (int)$currentUser['id'];
$isSuperAdmin = isSuperAdmin();

// Ensure Super Admin has ZERO mutation capability server-side except for Admin management
enforceWritePermission();

// Handle Super Admin POST Actions (Admin Role Designation & Revocation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isSuperAdmin()) {
        http_response_code(403);
        $_SESSION['admin_error'] = 'Access Denied: Only Super Admin can manage Administrator role designations.';
        header('Location: super_admin_governance.php?tab=admin_management');
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $_SESSION['admin_error'] = 'Security validation failed (invalid CSRF token).';
        header('Location: super_admin_governance.php?tab=admin_management');
        exit;
    }

    $action       = strtolower(trim($_POST['action'] ?? ''));
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'designate_admin' && $targetUserId > 0) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.role_id, u.status, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($target) {
            $targetRole = strtolower(trim($target['role_name']));
            if ($targetRole === 'admin') {
                $_SESSION['admin_error'] = "User '{$target['first_name']} {$target['last_name']}' is already an Administrator.";
            } elseif ($targetRole === 'super_admin') {
                $_SESSION['admin_error'] = "Cannot modify Super Admin governance account roles.";
            } else {
                // Designate Admin role (role_id = 4)
                $upStmt = $pdo->prepare("UPDATE users SET role_id = 4, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upStmt->execute([$targetUserId]);

                // Audit Log
                log_audit(
                    $pdo,
                    'admin_role_granted',
                    'users',
                    $targetUserId,
                    "Super Admin '{$currentUser['name']}' designated user '{$target['first_name']} {$target['last_name']}' (#{$targetUserId}) as System Administrator.",
                    $currentUserId
                );

                // User Notification
                createNotification(
                    $pdo,
                    $targetUserId,
                    'system_alert',
                    'Admin Role Granted',
                    "You have been designated as a System Administrator by the Super Admin governance authority.",
                    'user',
                    $targetUserId
                );

                $_SESSION['admin_success'] = "User '{$target['first_name']} {$target['last_name']}' (#{$targetUserId}) has been designated as a System Administrator.";
            }
        } else {
            $_SESSION['admin_error'] = 'Target user not found.';
        }
        header('Location: super_admin_governance.php?tab=admin_management');
        exit;

    } elseif ($action === 'revoke_admin' && $targetUserId > 0) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.role_id, u.status, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($target) {
            $targetRole = strtolower(trim($target['role_name']));
            if ($targetRole !== 'admin') {
                $_SESSION['admin_error'] = "User '{$target['first_name']} {$target['last_name']}' is not currently an Administrator.";
            } else {
                // Check Last-Admin protection
                $check = validateAdminModification($pdo, $targetUserId, $currentUserId, 'researcher', 'active');
                if (!$check['allowed']) {
                    $_SESSION['admin_error'] = $check['error'];
                } else {
                    // Check if target user has a supervisor profile (if so demote to supervisor, else researcher)
                    $spCheck = $pdo->prepare("SELECT id FROM supervisor_profiles WHERE user_id = ? LIMIT 1");
                    $spCheck->execute([$targetUserId]);
                    $newRoleId = $spCheck->fetch() ? 2 : 1;
                    $newRoleName = ($newRoleId === 2) ? 'supervisor' : 'researcher';

                    $upStmt = $pdo->prepare("UPDATE users SET role_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $upStmt->execute([$newRoleId, $targetUserId]);

                    // Audit Log
                    log_audit(
                        $pdo,
                        'admin_role_revoked',
                        'users',
                        $targetUserId,
                        "Super Admin '{$currentUser['name']}' revoked Administrator designation from user '{$target['first_name']} {$target['last_name']}' (#{$targetUserId}), demoting to '{$newRoleName}'.",
                        $currentUserId
                    );

                    // User Notification
                    createNotification(
                        $pdo,
                        $targetUserId,
                        'system_alert',
                        'Admin Designation Revoked',
                        "Your System Administrator designation has been revoked by Super Admin. Account role updated to '" . ucfirst($newRoleName) . "'.",
                        'user',
                        $targetUserId
                    );

                    $_SESSION['admin_success'] = "Administrator designation for '{$target['first_name']} {$target['last_name']}' (#{$targetUserId}) was successfully revoked.";
                }
            }
        } else {
            $_SESSION['admin_error'] = 'Target user not found.';
        }
        header('Location: super_admin_governance.php?tab=admin_management');
        exit;
    }
}

// Retrieve active tab
$activeTab = sanitize_input($_GET['tab'] ?? 'overview');
$allowedTabs = [
    'overview', 'admin_management', 'users', 'projects', 'supervision', 'submissions', 
    'communication', 'defense', 'repository', 'preservation', 
    'audit', 'security', 'system_health', 'reports'
];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

// Global Filter Parameters
$filterDept     = sanitize_input($_GET['department'] ?? '');
$filterFaculty  = sanitize_input($_GET['faculty'] ?? '');
$filterRole     = sanitize_input($_GET['role'] ?? '');
$filterStatus   = sanitize_input($_GET['status'] ?? '');
$filterSession  = sanitize_input($_GET['academic_session'] ?? '');
$filterSearch   = sanitize_input($_GET['search'] ?? '');
$filterDatePreset = sanitize_input($_GET['date_preset'] ?? 'all');
$filterDateStart  = sanitize_input($_GET['date_start'] ?? '');
$filterDateEnd    = sanitize_input($_GET['date_end'] ?? '');

// Unread Notifications & Flash Messages
$notifUnreadCount = getUnreadNotificationCount($pdo, $currentUserId);
$csrfToken = generateCsrfToken();
$adminSuccessMessage = $_SESSION['admin_success'] ?? '';
$adminErrorMessage   = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Scope for reports
$reportingScope = getReportingScope($currentUserId, 'super_admin');
$kpiMetrics = getInstitutionalKpiMetrics($pdo, $reportingScope);

// Data Fetching based on Active Tab
$adminList = [];
$eligibleUsersList = [];
$adminAuditList = [];
$userRoster = [];
$projectsList = [];
$supervisorsList = [];
$unassignedStudents = [];
$submissionsList = [];
$conversationsList = [];
$defensesList = [];
$repositoryList = [];
$preservationList = [];
$backupsList = [];
$auditLogsList = [];
$securityLogsList = [];
$systemHealth = [];

// 0. Fetch Admin Management Data
if ($activeTab === 'overview' || $activeTab === 'admin_management') {
    try {
        // Active & Total Admins
        $adminStmt = $pdo->query("
            SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.faculty, u.status, u.created_at, u.last_login_at, r.name AS role_name,
                   (SELECT COUNT(*) FROM audit_logs WHERE user_id = u.id) AS activity_count
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE r.name = 'admin'
            ORDER BY u.created_at ASC
        ");
        $adminList = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

        // Eligible Users for Admin Designation
        $eWhere = ["r.name NOT IN ('admin', 'super_admin')", "u.status = 'active'"];
        $eParams = [];
        if (!empty($filterSearch)) {
            $eWhere[] = "(u.first_name LIKE :e_q OR u.last_name LIKE :e_q OR u.email LIKE :e_q OR u.department LIKE :e_q)";
            $eParams[':e_q'] = "%{$filterSearch}%";
        }
        $eClause = implode(" AND ", $eWhere);
        $eStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.faculty, u.status, u.created_at, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE {$eClause}
            ORDER BY u.created_at DESC
            LIMIT 100
        ");
        $eStmt->execute($eParams);
        $eligibleUsersList = $eStmt->fetchAll(PDO::FETCH_ASSOC);

        // Admin Audit Trail
        $auStmt = $pdo->query("
            SELECT a.*, u.first_name, u.last_name, u.email, r.name AS role_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE a.action IN ('admin_role_granted', 'admin_role_revoked')
               OR a.description LIKE '%designated%' OR a.description LIKE '%revoked%'
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 50
        ");
        $adminAuditList = $auStmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Super Admin Admin Management Query Error: " . $e->getMessage());
    }
}

// 1. Fetch User Roster
if ($activeTab === 'overview' || $activeTab === 'users') {
    try {
        $uWhere = ["1=1"];
        $uParams = [];
        if (!empty($filterRole)) {
            $uWhere[] = "r.name = :f_role";
            $uParams[':f_role'] = $filterRole;
        }
        if (!empty($filterDept)) {
            $uWhere[] = "u.department = :f_dept";
            $uParams[':f_dept'] = $filterDept;
        }
        if (!empty($filterStatus)) {
            $uWhere[] = "u.status = :f_status";
            $uParams[':f_status'] = $filterStatus;
        }
        if (!empty($filterSearch)) {
            $uWhere[] = "(u.first_name LIKE :f_q OR u.last_name LIKE :f_q OR u.email LIKE :f_q OR u.username LIKE :f_q)";
            $uParams[':f_q'] = "%{$filterSearch}%";
        }
        $uClause = implode(" AND ", $uWhere);
        $uStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.username, u.department, u.faculty, u.status, u.created_at, u.last_login, r.name AS role_name, r.description AS role_desc, p.name AS programme_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            LEFT JOIN programmes p ON u.programme_id = p.id
            WHERE {$uClause}
            ORDER BY u.created_at DESC
            LIMIT 200
        ");
        $uStmt->execute($uParams);
        $userRoster = $uStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin User Roster Query Error: " . $e->getMessage());
    }
}

// 2. Fetch Projects Governance Data
if ($activeTab === 'overview' || $activeTab === 'projects') {
    try {
        $pWhere = ["1=1"];
        $pParams = [];
        if (!empty($filterStatus)) {
            $pWhere[] = "p.status = :p_status";
            $pParams[':p_status'] = $filterStatus;
        }
        if (!empty($filterDept)) {
            $pWhere[] = "st.department = :p_dept";
            $pParams[':p_dept'] = $filterDept;
        }
        if (!empty($filterSearch)) {
            $pWhere[] = "(p.title LIKE :p_q OR p.project_code LIKE :p_q OR st.first_name LIKE :p_q OR st.last_name LIKE :p_q)";
            $pParams[':p_q'] = "%{$filterSearch}%";
        }
        $pClause = implode(" AND ", $pWhere);
        $pStmt = $pdo->prepare("
            SELECT p.*, 
                   st.first_name AS student_first, st.last_name AS student_last, st.email AS student_email, st.department AS student_dept,
                   sup.first_name AS supervisor_first, sup.last_name AS supervisor_last,
                   pd.status AS defense_status, pd.defense_date,
                   (SELECT COUNT(*) FROM project_milestones WHERE project_id = p.id AND status = 'completed') AS completed_milestones_count,
                   (SELECT COUNT(*) FROM project_milestones WHERE project_id = p.id) AS total_milestones_count,
                   (SELECT COUNT(*) FROM datasets WHERE project_id = p.id AND status = 'published') AS published_datasets_count
            FROM research_projects p
            INNER JOIN users st ON p.owner_id = st.id
            LEFT JOIN student_supervisors ss ON st.id = ss.student_id AND ss.status = 'active'
            LEFT JOIN users sup ON ss.supervisor_id = sup.id
            LEFT JOIN project_defenses pd ON p.id = pd.project_id
            WHERE {$pClause}
            ORDER BY p.updated_at DESC
            LIMIT 150
        ");
        $pStmt->execute($pParams);
        $projectsList = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Projects Query Error: " . $e->getMessage());
    }
}

// 3. Fetch Supervision Workload & Unassigned Students
if ($activeTab === 'overview' || $activeTab === 'supervision') {
    try {
        $supervisorsList = getSupervisorWorkloadAnalytics($pdo, ['department' => $filterDept]);
        $unassignedStudents = getUnassignedStudentsReport($pdo, ['department' => $filterDept]);
    } catch (PDOException $e) {
        error_log("Super Admin Supervision Query Error: " . $e->getMessage());
    }
}

// 4. Submissions & Reviews Monitoring
if ($activeTab === 'submissions') {
    try {
        $subStmt = $pdo->prepare("
            SELECT ps.*, rp.title AS project_title, rp.project_code,
                   st.first_name AS student_first, st.last_name AS student_last,
                   sr.review_decision, sr.comments AS review_comments, sr.reviewed_at,
                   rev.first_name AS reviewer_first, rev.last_name AS reviewer_last
            FROM project_submissions ps
            INNER JOIN research_projects rp ON ps.project_id = rp.id
            INNER JOIN users st ON ps.submitted_by = st.id
            LEFT JOIN supervisor_reviews sr ON ps.id = sr.submission_id
            LEFT JOIN users rev ON sr.reviewer_id = rev.id
            ORDER BY ps.created_at DESC
            LIMIT 150
        ");
        $subStmt->execute();
        $submissionsList = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Submissions Query Error: " . $e->getMessage());
    }
}

// 5. Communication Monitoring
if ($activeTab === 'communication') {
    try {
        $cStmt = $pdo->prepare("
            SELECT pc.*, rp.title AS project_title, rp.project_code,
                   st.first_name AS student_first, st.last_name AS student_last,
                   sup.first_name AS supervisor_first, sup.last_name AS supervisor_last,
                   (SELECT COUNT(*) FROM project_messages WHERE conversation_id = pc.id) AS total_messages,
                   (SELECT MAX(created_at) FROM project_messages WHERE conversation_id = pc.id) AS latest_message_at
            FROM project_conversations pc
            INNER JOIN research_projects rp ON pc.project_id = rp.id
            INNER JOIN users st ON pc.student_id = st.id
            INNER JOIN users sup ON pc.supervisor_id = sup.id
            ORDER BY latest_message_at DESC, pc.updated_at DESC
            LIMIT 100
        ");
        $cStmt->execute();
        $conversationsList = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Communication Query Error: " . $e->getMessage());
    }
}

// 6. Defense & Viva Monitoring
if ($activeTab === 'overview' || $activeTab === 'defense') {
    try {
        $defStmt = $pdo->prepare("
            SELECT d.*, rp.title AS project_title, rp.project_code,
                   st.first_name AS student_first, st.last_name AS student_last, st.department AS student_dept,
                   o.outcome, o.decision_date, o.overall_remarks, o.corrections_required_summary,
                   (SELECT COUNT(*) FROM defense_panel_members WHERE defense_id = d.id) AS panel_count
            FROM project_defenses d
            INNER JOIN research_projects rp ON d.project_id = rp.id
            INNER JOIN users st ON rp.owner_id = st.id
            LEFT JOIN defense_outcomes o ON d.id = o.defense_id
            ORDER BY d.defense_date DESC, d.created_at DESC
            LIMIT 100
        ");
        $defStmt->execute();
        $defensesList = $defStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Defense Query Error: " . $e->getMessage());
    }
}

// 7. Repository & Publication Monitoring
if ($activeTab === 'overview' || $activeTab === 'repository') {
    try {
        $repoStmt = $pdo->prepare("
            SELECT d.*, rp.title AS project_title, rp.project_code,
                   u.first_name AS owner_first, u.last_name AS owner_last,
                   di.identifier_value AS doi_handle,
                   pr.preservation_status, pr.checksum_verified
            FROM datasets d
            LEFT JOIN research_projects rp ON d.project_id = rp.id
            INNER JOIN users u ON d.owner_id = u.id
            LEFT JOIN dataset_identifiers di ON d.id = di.dataset_id AND di.identifier_type IN ('doi', 'hdl')
            LEFT JOIN preservation_records pr ON d.id = pr.dataset_id
            WHERE d.status != 'deleted'
            ORDER BY d.created_at DESC
            LIMIT 100
        ");
        $repoStmt->execute();
        $repositoryList = $repoStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Repository Query Error: " . $e->getMessage());
    }
}

// 8. Preservation & Backups Monitoring
if ($activeTab === 'overview' || $activeTab === 'preservation') {
    try {
        $presStmt = $pdo->query("
            SELECT pr.*, d.title AS dataset_title, d.access_level, u.first_name, u.last_name
            FROM preservation_records pr
            INNER JOIN datasets d ON pr.dataset_id = d.id
            INNER JOIN users u ON d.owner_id = u.id
            ORDER BY pr.created_at DESC LIMIT 100
        ");
        $preservationList = $presStmt->fetchAll(PDO::FETCH_ASSOC);

        $bkStmt = $pdo->query("SELECT * FROM backups ORDER BY created_at DESC LIMIT 50");
        $backupsList = $bkStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Preservation Query Error: " . $e->getMessage());
    }
}

// 9. Audit Logs Center
if ($activeTab === 'overview' || $activeTab === 'audit') {
    try {
        $dateFilter = buildDateFilterClause($filterDatePreset, $filterDateStart, $filterDateEnd, 'a.timestamp', 'a_');
        $aWhere = [$dateFilter['clause']];
        $aParams = $dateFilter['params'];

        if (!empty($filterSearch)) {
            $aWhere[] = "(a.action LIKE :a_q OR a.description LIKE :a_q OR u.first_name LIKE :a_q OR u.last_name LIKE :a_q)";
            $aParams[':a_q'] = "%{$filterSearch}%";
        }
        $aClause = implode(" AND ", $aWhere);

        $aStmt = $pdo->prepare("
            SELECT a.*, u.first_name, u.last_name, u.email, r.name AS role_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE {$aClause}
            ORDER BY a.timestamp DESC
            LIMIT 150
        ");
        $aStmt->execute($aParams);
        $auditLogsList = $aStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Audit Query Error: " . $e->getMessage());
    }
}

// 10. Security Event Monitoring
if ($activeTab === 'security') {
    try {
        $secStmt = $pdo->prepare("
            SELECT a.*, u.first_name, u.last_name, u.email, r.name AS role_name
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE a.action IN ('failed_login', 'unauthorized_access', 'permission_denied', 'role_changed', 'setting_updated', 'user_suspended', 'super_admin_write_blocked')
               OR a.description LIKE '%denied%' OR a.description LIKE '%failed%' OR a.description LIKE '%unauthorized%'
            ORDER BY a.timestamp DESC
            LIMIT 100
        ");
        $secStmt->execute();
        $securityLogsList = $secStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Super Admin Security Query Error: " . $e->getMessage());
    }
}

// 11. Safe System Health Indicators
if ($activeTab === 'overview' || $activeTab === 'system_health') {
    $dbOk = false;
    try {
        $dbOk = ($pdo->query("SELECT 1")->fetchColumn() === 1);
    } catch (Exception $ex) {
        $dbOk = false;
    }

    $uploadDir = realpath(__DIR__ . '/../storage/uploads');
    $uploadOk  = $uploadDir && is_dir($uploadDir) && is_writable($uploadDir);

    $exts = ['pdo', 'pdo_mysql', 'gd', 'curl', 'json', 'mbstring'];
    $extStatus = [];
    foreach ($exts as $ext) {
        $extStatus[$ext] = extension_loaded($ext);
    }

    $lastBackup = null;
    try {
        $bRow = $pdo->query("SELECT created_at, status FROM backups ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($bRow) $lastBackup = $bRow;
    } catch (Exception $e) {}

    $systemHealth = [
        'database_status'     => $dbOk ? 'Healthy' : 'Error',
        'upload_dir_writable' => $uploadOk ? 'Writable' : 'Restricted',
        'php_version'         => PHP_VERSION,
        'extensions'          => $extStatus,
        'last_backup'         => $lastBackup
    ];
}

// Departments List for filter dropdown
$departmentsList = [];
try {
    $departmentsList = $pdo->query("SELECT DISTINCT name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Institutional Governance — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .gov-banner {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
            color: #ffffff;
            padding: 1.75rem 2rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.75rem;
            box-shadow: var(--shadow-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .gov-badge {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            color: #ffffff;
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .gov-tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            padding-bottom: 0.25rem;
        }
        .gov-tab {
            padding: 0.65rem 1.15rem;
            border-radius: var(--radius-sm) var(--radius-sm) 0 0;
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .gov-tab:hover {
            color: var(--primary-color);
            background: #f1f5f9;
        }
        .gov-tab.active {
            color: var(--primary-color);
            border-bottom: 3px solid var(--accent-color);
            background: #ffffff;
            font-weight: 700;
        }
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        .form-group-sm label {
            display: block;
            font-size: 0.775rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
        }
        .form-control-sm {
            width: 100%;
            padding: 0.45rem 0.75rem;
            font-size: 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: #f8fafc;
        }
        .readonly-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #6366f1;
            background: #e0e7ff;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.75rem;
        }
        .kpi-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
        }
        .kpi-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        .kpi-val {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--primary-color);
        }
        .kpi-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="dash-navbar">
        <a href="super_admin_governance.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Super Admin Governance Center</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="../notifications/index.php" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; margin-right: 0.75rem; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);" title="Notifications">
                <span>🔔</span>
                <?php if ($notifUnreadCount > 0): ?>
                    <span style="background: #059669; color: #ffffff; font-size: 0.75rem; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                        <?= $notifUnreadCount; ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($currentUser['name']); ?></div>
                    <div class="user-affiliation">Institutional Governance Monitor</div>
                </div>
                <span class="role-badge" style="background: #4338ca; color: #ffffff;">
                    Super Admin (Read-Only)
                </span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Container -->
    <main class="dash-container">

        <!-- Flash Notices -->
        <?php if (!empty($adminSuccessMessage)): ?>
            <div class="dash-alert dash-alert-success" style="margin-bottom: 1.5rem; background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 1rem 1.25rem; border-radius: var(--radius-sm); font-weight: 600;">
                <span>✅ <?= e($adminSuccessMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($adminErrorMessage)): ?>
            <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem; background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 1rem 1.25rem; border-radius: var(--radius-sm); font-weight: 600;">
                <span>⚠️ <?= e($adminErrorMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- Read-Only Banner -->
        <div class="gov-banner">
            <div>
                <div class="gov-badge">👁️ Institutional Governance & Oversight</div>
                <h1 style="margin: 0.5rem 0 0.25rem 0; font-size: 1.65rem; color: #ffffff;">Super Admin Monitoring & Governance Hub</h1>
                <p style="margin: 0; color: #c7d2fe; font-size: 0.9rem;">
                    Institutional oversight, Admin designation management, supervision workload, defense outcomes, FAIR data publishing, and security audit activity.
                </p>
            </div>
            <div style="text-align: right;">
                <span class="readonly-indicator" style="background: rgba(255,255,255,0.2); color: #ffffff; padding: 0.5rem 1rem; border-radius: var(--radius-sm); font-size: 0.85rem;">
                    🛡️ Controlled Admin Management Active
                </span>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="gov-tabs">
            <a href="?tab=overview" class="gov-tab <?= $activeTab === 'overview' ? 'active' : ''; ?>">📊 Overview & KPIs</a>
            <a href="?tab=admin_management" class="gov-tab <?= $activeTab === 'admin_management' ? 'active' : ''; ?>" style="font-weight: 700; color: #4338ca;">👑 Admin Management</a>
            <a href="?tab=users" class="gov-tab <?= $activeTab === 'users' ? 'active' : ''; ?>">👥 User Roster</a>
            <a href="?tab=projects" class="gov-tab <?= $activeTab === 'projects' ? 'active' : ''; ?>">📁 Research Projects</a>
            <a href="?tab=supervision" class="gov-tab <?= $activeTab === 'supervision' ? 'active' : ''; ?>">👨‍🏫 Supervision Workload</a>
            <a href="?tab=submissions" class="gov-tab <?= $activeTab === 'submissions' ? 'active' : ''; ?>">📑 Submissions & Reviews</a>
            <a href="?tab=communication" class="gov-tab <?= $activeTab === 'communication' ? 'active' : ''; ?>">💬 Messaging Audit</a>
            <a href="?tab=defense" class="gov-tab <?= $activeTab === 'defense' ? 'active' : ''; ?>">🎓 Defense & Viva</a>
            <a href="?tab=repository" class="gov-tab <?= $activeTab === 'repository' ? 'active' : ''; ?>">🌐 Repository & FAIR</a>
            <a href="?tab=preservation" class="gov-tab <?= $activeTab === 'preservation' ? 'active' : ''; ?>">💾 Preservation & Backups</a>
            <a href="?tab=audit" class="gov-tab <?= $activeTab === 'audit' ? 'active' : ''; ?>">🛡️ Audit Logs</a>
            <a href="?tab=security" class="gov-tab <?= $activeTab === 'security' ? 'active' : ''; ?>">🔒 Security Events</a>
            <a href="?tab=system_health" class="gov-tab <?= $activeTab === 'system_health' ? 'active' : ''; ?>">🖥️ System Health</a>
            <a href="../reports/index.php" class="gov-tab" style="color: var(--accent-color);">📈 Institutional Reports &rarr;</a>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <form method="GET" action="super_admin_governance.php" class="filter-grid">
                <input type="hidden" name="tab" value="<?= e($activeTab); ?>">
                
                <div class="form-group-sm">
                    <label>Search Keyword</label>
                    <input type="text" name="search" class="form-control-sm" placeholder="Title, Name, Code, Email..." value="<?= e($filterSearch); ?>">
                </div>

                <div class="form-group-sm">
                    <label>Department</label>
                    <select name="department" class="form-control-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departmentsList as $d): ?>
                            <option value="<?= e($d); ?>" <?= $filterDept === $d ? 'selected' : ''; ?>><?= e($d); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($activeTab === 'users'): ?>
                    <div class="form-group-sm">
                        <label>User Role</label>
                        <select name="role" class="form-control-sm">
                            <option value="">All Roles</option>
                            <option value="researcher" <?= $filterRole === 'researcher' ? 'selected' : ''; ?>>Researcher / Student</option>
                            <option value="supervisor" <?= $filterRole === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                            <option value="librarian" <?= $filterRole === 'librarian' ? 'selected' : ''; ?>>Librarian</option>
                            <option value="admin" <?= $filterRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="super_admin" <?= $filterRole === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($activeTab === 'audit' || $activeTab === 'overview'): ?>
                    <div class="form-group-sm">
                        <label>Date Filter</label>
                        <select name="date_preset" class="form-control-sm">
                            <option value="all" <?= $filterDatePreset === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="today" <?= $filterDatePreset === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="7days" <?= $filterDatePreset === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30days" <?= $filterDatePreset === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group-sm" style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn btn-primary btn-sm" style="flex: 1; padding: 0.45rem 1rem; font-weight: 700;">Filter</button>
                    <a href="super_admin_governance.php?tab=<?= e($activeTab); ?>" class="btn btn-outline btn-sm" style="padding: 0.45rem 0.85rem;">Reset</a>
                </div>
            </form>
        </div>

        <!-- TAB CONTENT: OVERVIEW -->
        <?php if ($activeTab === 'overview'): ?>
            <h2 class="section-title">Institutional Monitoring Indicators</h2>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-title">Institutional Students</div>
                    <div class="kpi-val"><?= $kpiMetrics['total_students']; ?></div>
                    <div class="kpi-sub"><?= $kpiMetrics['unassigned_students']; ?> Unassigned Students</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Active Supervisors</div>
                    <div class="kpi-val"><?= $kpiMetrics['total_supervisors']; ?></div>
                    <div class="kpi-sub">Supervision Capacity Active</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Research Projects</div>
                    <div class="kpi-val"><?= $kpiMetrics['total_projects']; ?></div>
                    <div class="kpi-sub"><?= $kpiMetrics['active_projects']; ?> Active &bull; <?= $kpiMetrics['completed_projects']; ?> Completed</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Oral Defenses (Viva)</div>
                    <div class="kpi-val"><?= $kpiMetrics['scheduled_defenses'] + $kpiMetrics['completed_defenses']; ?></div>
                    <div class="kpi-sub"><?= $kpiMetrics['completed_defenses']; ?> Completed Outcomes Recorded</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Repository Publications</div>
                    <div class="kpi-val"><?= $kpiMetrics['published_projects']; ?></div>
                    <div class="kpi-sub"><?= $kpiMetrics['pending_repository_review']; ?> Pending Repository Review</div>
                </div>
            </div>

            <!-- Quick Read-Only Status Summaries -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                <div class="content-card">
                    <h3 style="margin-top: 0; font-size: 1.1rem; color: var(--primary-color);">👨‍🏫 Supervision Overview</h3>
                    <p style="font-size: 0.875rem; color: var(--text-muted);">
                        Currently <strong><?= $kpiMetrics['unassigned_students']; ?></strong> active student(s) require supervisor allocation.
                        <strong><?= $kpiMetrics['awaiting_supervisor_review']; ?></strong> document submission(s) await supervisor review.
                    </p>
                    <a href="?tab=supervision" class="btn btn-outline btn-sm">Inspect Supervision Workload &rarr;</a>
                </div>

                <div class="content-card">
                    <h3 style="margin-top: 0; font-size: 1.1rem; color: var(--primary-color);">🎓 Defense & Viva Readiness</h3>
                    <p style="font-size: 0.875rem; color: var(--text-muted);">
                        <strong><?= $kpiMetrics['approved_for_defense']; ?></strong> project(s) authorized for defense by supervisors.
                        <strong><?= $kpiMetrics['awaiting_final_signoff']; ?></strong> project(s) pending final institutional sign-off.
                    </p>
                    <a href="?tab=defense" class="btn btn-outline btn-sm">Inspect Defense Schedules &rarr;</a>
                </div>

                <div class="content-card">
                    <h3 style="margin-top: 0; font-size: 1.1rem; color: var(--primary-color);">💾 System Health & Preservation</h3>
                    <p style="font-size: 0.875rem; color: var(--text-muted);">
                        Database Status: <strong><?= e($systemHealth['database_status']); ?></strong> &bull; Storage Uploads: <strong><?= e($systemHealth['upload_dir_writable']); ?></strong><br>
                        Last Backup: <?= $systemHealth['last_backup'] ? e($systemHealth['last_backup']['created_at']) : 'None Recorded'; ?>
                    </p>
                    <a href="?tab=system_health" class="btn btn-outline btn-sm">Inspect System Health &rarr;</a>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: ADMIN MANAGEMENT -->
        <?php if ($activeTab === 'admin_management'): ?>
            
            <!-- Hierarchy & Governance Role Explanation -->
            <div style="background: #e0e7ff; border: 1px solid #c7d2fe; padding: 1.25rem 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; color: #1e1b4b;">
                <div style="font-weight: 800; font-size: 1.05rem; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span>👑 Core Institutional Role Hierarchy & Designation Authority</span>
                </div>
                <p style="margin: 0; font-size: 0.875rem; color: #3730a3; line-height: 1.5;">
                    <strong>Super Admin Authority:</strong> Super Admin is the sole governance authority responsible for designating and revoking <strong>System Administrators</strong>.<br>
                    <strong>Admin Operational Scope:</strong> Designated System Administrators manage day-to-day operational functions including user accounts, supervisors, student assignments, research projects, and academic structure. Admins <em>cannot</em> grant or revoke the Admin role.
                </p>
            </div>

            <!-- Current Administrators Section -->
            <div class="content-card" style="margin-bottom: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div>
                        <h2 class="section-title" style="margin: 0;">🛡️ Current System Administrators (<?= count($adminList); ?> Designated)</h2>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">
                            Active administrators holding operational system permissions. Multiple simultaneous administrators are supported.
                        </div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Administrator Name</th>
                                <th>Email Address</th>
                                <th>Department / Faculty</th>
                                <th>Status</th>
                                <th>Designation Date</th>
                                <th>Last Active</th>
                                <th>System Activity</th>
                                <th>Governance Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminList as $ad): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($ad['first_name'] . ' ' . $ad['last_name']); ?></strong>
                                    </td>
                                    <td><?= e($ad['email']); ?></td>
                                    <td><?= e($ad['department'] ?: ($ad['faculty'] ?: 'Institutional Administration')); ?></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($ad['status']); ?>">
                                            <?= e(ucfirst($ad['status'])); ?>
                                        </span>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($ad['created_at'])); ?></small></td>
                                    <td><small><?= $ad['last_login_at'] ? date('M d, Y H:i', strtotime($ad['last_login_at'])) : 'Never'; ?></small></td>
                                    <td><small><?= (int)$ad['activity_count']; ?> Audit Logs</small></td>
                                    <td>
                                        <form method="POST" action="super_admin_governance.php?tab=admin_management" style="display: inline;" onsubmit="return confirm('Are you sure you want to revoke Admin designation from <?= e(addslashes($ad['first_name'] . ' ' . $ad['last_name'])); ?>? This will demote the user to their standard operational role.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="revoke_admin">
                                            <input type="hidden" name="user_id" value="<?= (int)$ad['id']; ?>">
                                            <button type="submit" class="btn btn-sm" style="background: #dc2626; color: #ffffff; font-weight: 700; border: none; padding: 0.35rem 0.75rem; border-radius: 4px; cursor: pointer;" title="Revoke Admin Role">
                                                ⚠️ Revoke Admin
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($adminList)): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">No system administrators designated.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Eligible Users for Admin Designation Section -->
            <div class="content-card" style="margin-bottom: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <h2 class="section-title" style="margin: 0;">➕ Designate New System Administrator</h2>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">
                            Select an active user/staff member to grant System Administrator operational privileges.
                        </div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>User Candidate</th>
                                <th>Email</th>
                                <th>Current Role</th>
                                <th>Department / Faculty</th>
                                <th>Status</th>
                                <th>Registration Date</th>
                                <th>Designation Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eligibleUsersList as $eg): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($eg['first_name'] . ' ' . $eg['last_name']); ?></strong>
                                    </td>
                                    <td><?= e($eg['email']); ?></td>
                                    <td>
                                        <span class="role-badge" style="background: #f1f5f9; color: #334155;">
                                            <?= e(ucfirst($eg['role_name'])); ?>
                                        </span>
                                    </td>
                                    <td><?= e($eg['department'] ?: ($eg['faculty'] ?: '—')); ?></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($eg['status']); ?>">
                                            <?= e(ucfirst($eg['status'])); ?>
                                        </span>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($eg['created_at'])); ?></small></td>
                                    <td>
                                        <form method="POST" action="super_admin_governance.php?tab=admin_management" style="display: inline;" onsubmit="return confirm('Designate <?= e(addslashes($eg['first_name'] . ' ' . $eg['last_name'])); ?> as a System Administrator? The user will be granted operational administrative access.');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="designate_admin">
                                            <input type="hidden" name="user_id" value="<?= (int)$eg['id']; ?>">
                                            <button type="submit" class="btn btn-sm" style="background: #2563eb; color: #ffffff; font-weight: 700; border: none; padding: 0.35rem 0.75rem; border-radius: 4px; cursor: pointer;" title="Grant Admin Designation">
                                                + Designate as Admin
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($eligibleUsersList)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No eligible users found matching the search criteria.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Admin Role Designation Audit Trail -->
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">🛡️ Admin Designation & Revocation Audit History</h2>
                    <span class="readonly-indicator">Immutable Audit Log</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Governance Action</th>
                                <th>Target User</th>
                                <th>Narrative / Context</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminAuditList as $au): ?>
                                <tr>
                                    <td><small><?= date('M d, Y H:i:s', strtotime($au['created_at'])); ?></small></td>
                                    <td>
                                        <span class="role-badge" style="background: <?= $au['action'] === 'admin_role_granted' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;'; ?> font-weight: 700;">
                                            <?= e($au['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $au['first_name'] ? e($au['first_name'] . ' ' . $au['last_name'] . ' (' . $au['email'] . ')') : ('User #' . (int)$au['entity_id']); ?>
                                    </td>
                                    <td><?= e($au['description']); ?></td>
                                    <td><small style="font-family: monospace;"><?= e($au['ip_address']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($adminAuditList)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No Admin designation audit events logged yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

        <!-- TAB CONTENT: USERS -->
        <?php if ($activeTab === 'users'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Institutional User Roster (<?= count($userRoster); ?> Records)</h2>
                    <span class="readonly-indicator">Read-Only View</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Name / Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Department</th>
                                <th>Programme</th>
                                <th>Status</th>
                                <th>Registered</th>
                                <th>Last Login</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userRoster as $u): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($u['first_name'] . ' ' . $u['last_name']); ?></strong><br>
                                        <small style="color: var(--text-muted);"><?= e($u['username']); ?></small>
                                    </td>
                                    <td><?= e($u['email']); ?></td>
                                    <td>
                                        <span class="role-badge" style="background: #e2e8f0; color: #1e293b;">
                                            <?= e(ucfirst(str_replace('_', ' ', $u['role_name']))); ?>
                                        </span>
                                    </td>
                                    <td><?= e($u['department'] ?: '—'); ?></td>
                                    <td><?= e($u['programme_name'] ?: '—'); ?></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($u['status']); ?>">
                                            <?= e(ucfirst($u['status'])); ?>
                                        </span>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($u['created_at'])); ?></small></td>
                                    <td><small><?= $u['last_login'] ? date('M d, Y H:i', strtotime($u['last_login'])) : 'Never'; ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($userRoster)): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">No user records match the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: PROJECTS -->
        <?php if ($activeTab === 'projects'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Institutional Research Projects Governance</h2>
                    <span class="readonly-indicator">Read-Only View</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Project Code / Title</th>
                                <th>Student</th>
                                <th>Supervisor</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Milestones</th>
                                <th>Defense</th>
                                <th>Datasets</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projectsList as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($p['project_code']); ?></strong><br>
                                        <?= e(mb_strimwidth($p['title'], 0, 50, '...')); ?>
                                    </td>
                                    <td><?= e($p['student_first'] . ' ' . $p['student_last']); ?></td>
                                    <td><?= $p['supervisor_first'] ? e($p['supervisor_first'] . ' ' . $p['supervisor_last']) : '<span style="color: #dc2626;">Unassigned</span>'; ?></td>
                                    <td><?= e($p['student_dept'] ?: '—'); ?></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($p['status']); ?>">
                                            <?= e(ucfirst(str_replace('_', ' ', $p['status']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= (int)$p['completed_milestones_count']; ?> / <?= (int)$p['total_milestones_count']; ?> Done</small>
                                    </td>
                                    <td>
                                        <small><?= $p['defense_status'] ? e(ucfirst($p['defense_status'])) : 'Not Scheduled'; ?></small>
                                    </td>
                                    <td>
                                        <small><?= (int)$p['published_datasets_count']; ?> Published</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($projectsList)): ?>
                                <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">No research project records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: SUPERVISION -->
        <?php if ($activeTab === 'supervision'): ?>
            <div class="content-card" style="margin-bottom: 1.5rem;">
                <h2 class="section-title">Supervisor Workload & Capacity Overview</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Supervisor Name</th>
                                <th>Department</th>
                                <th>Assigned Students</th>
                                <th>Active Projects</th>
                                <th>Pending Reviews</th>
                                <th>Open Corrections</th>
                                <th>Overdue Milestones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($supervisorsList as $sup): ?>
                                <tr>
                                    <td><strong><?= e($sup['first_name'] . ' ' . $sup['last_name']); ?></strong></td>
                                    <td><?= e($sup['department'] ?: '—'); ?></td>
                                    <td><strong><?= (int)$sup['assigned_students_count']; ?></strong></td>
                                    <td><?= (int)$sup['active_projects_count']; ?></td>
                                    <td>
                                        <?php if ($sup['pending_reviews_count'] > 0): ?>
                                            <span style="color: #d97706; font-weight: 700;"><?= (int)$sup['pending_reviews_count']; ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                    <td><?= (int)$sup['open_corrections_count']; ?></td>
                                    <td>
                                        <?php if ($sup['overdue_milestones_count'] > 0): ?>
                                            <span style="color: #dc2626; font-weight: 700;"><?= (int)$sup['overdue_milestones_count']; ?></span>
                                        <?php else: ?>0<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <h2 class="section-title">Unassigned Active Students (<?= count($unassignedStudents); ?>)</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Email</th>
                                <th>Matric Number</th>
                                <th>Department</th>
                                <th>Programme</th>
                                <th>Project Title</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unassignedStudents as $st): ?>
                                <tr>
                                    <td><strong><?= e($st['first_name'] . ' ' . $st['last_name']); ?></strong></td>
                                    <td><?= e($st['email']); ?></td>
                                    <td><?= e($st['matric_number'] ?: 'N/A'); ?></td>
                                    <td><?= e($st['department'] ?: '—'); ?></td>
                                    <td><?= e($st['programme_name'] ?: '—'); ?></td>
                                    <td><?= $st['project_title'] ? e($st['project_title']) : '<span style="color: var(--text-muted);">No Project Created</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($unassignedStudents)): ?>
                                <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">All active students currently have an assigned supervisor.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: SUBMISSIONS & REVIEWS -->
        <?php if ($activeTab === 'submissions'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Academic Submission & Review Lifecycle</h2>
                    <span class="readonly-indicator">Read-Only View</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Submission Type</th>
                                <th>Project Code / Title</th>
                                <th>Student</th>
                                <th>Version</th>
                                <th>Submitted Date</th>
                                <th>Status</th>
                                <th>Reviewer</th>
                                <th>Review Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($submissionsList as $sub): ?>
                                <tr>
                                    <td><strong><?= e(str_replace('_', ' ', ucfirst($sub['submission_type']))); ?></strong></td>
                                    <td>
                                        <strong><?= e($sub['project_code']); ?></strong><br>
                                        <small><?= e(mb_strimwidth($sub['project_title'], 0, 40, '...')); ?></small>
                                    </td>
                                    <td><?= e($sub['student_first'] . ' ' . $sub['student_last']); ?></td>
                                    <td>v<?= (int)$sub['version_number']; ?></td>
                                    <td><small><?= date('M d, Y H:i', strtotime($sub['created_at'])); ?></small></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($sub['status']); ?>">
                                            <?= e(ucfirst(str_replace('_', ' ', $sub['status']))); ?>
                                        </span>
                                    </td>
                                    <td><?= $sub['reviewer_first'] ? e($sub['reviewer_first'] . ' ' . $sub['reviewer_last']) : '—'; ?></td>
                                    <td>
                                        <?php if ($sub['review_decision']): ?>
                                            <strong><?= e(ucfirst(str_replace('_', ' ', $sub['review_decision']))); ?></strong>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Awaiting Review</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: MESSAGING AUDIT -->
        <?php if ($activeTab === 'communication'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Student–Supervisor Messaging Oversight</h2>
                    <span class="readonly-indicator">Read-Only Governance</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Project Code / Title</th>
                                <th>Student</th>
                                <th>Supervisor</th>
                                <th>Total Messages</th>
                                <th>Latest Message Timestamp</th>
                                <th>Conversation Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conversationsList as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($c['project_code']); ?></strong><br>
                                        <small><?= e($c['project_title']); ?></small>
                                    </td>
                                    <td><?= e($c['student_first'] . ' ' . $c['student_last']); ?></td>
                                    <td><?= e($c['supervisor_first'] . ' ' . $c['supervisor_last']); ?></td>
                                    <td><strong><?= (int)$c['total_messages']; ?></strong> msgs</td>
                                    <td><small><?= $c['latest_message_at'] ? date('M d, Y H:i', strtotime($c['latest_message_at'])) : 'No messages'; ?></small></td>
                                    <td><span class="status-pill status-active">Active Thread</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: DEFENSE -->
        <?php if ($activeTab === 'defense'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Oral Defense & Examination Schedules</h2>
                    <span class="readonly-indicator">Read-Only View</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Project Code / Title</th>
                                <th>Student</th>
                                <th>Defense Date & Time</th>
                                <th>Venue</th>
                                <th>Panel Members</th>
                                <th>Status</th>
                                <th>Recorded Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($defensesList as $def): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($def['project_code']); ?></strong><br>
                                        <small><?= e(mb_strimwidth($def['project_title'], 0, 45, '...')); ?></small>
                                    </td>
                                    <td><?= e($def['student_first'] . ' ' . $def['student_last']); ?></td>
                                    <td>
                                        <strong><?= date('M d, Y', strtotime($def['defense_date'])); ?></strong><br>
                                        <small><?= e($def['start_time']); ?></small>
                                    </td>
                                    <td><?= e($def['venue']); ?></td>
                                    <td><strong><?= (int)$def['panel_count']; ?></strong> Members</td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($def['status']); ?>">
                                            <?= e(ucfirst($def['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($def['outcome']): ?>
                                            <strong><?= e(ucfirst(str_replace('_', ' ', $def['outcome']))); ?></strong>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Pending Viva</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: REPOSITORY -->
        <?php if ($activeTab === 'repository'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Repository Submissions & FAIR Metadata</h2>
                    <span class="readonly-indicator">Read-Only Governance</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Dataset Title</th>
                                <th>Owner / Student</th>
                                <th>Access Level</th>
                                <th>Status</th>
                                <th>DOI / Identifier</th>
                                <th>Preservation Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repositoryList as $repo): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($repo['title']); ?></strong><br>
                                        <small style="color: var(--text-muted);"><?= e($repo['project_title'] ?: 'Standalone Dataset'); ?></small>
                                    </td>
                                    <td><?= e($repo['owner_first'] . ' ' . $repo['owner_last']); ?></td>
                                    <td><span class="status-pill"><?= e(ucfirst($repo['access_level'])); ?></span></td>
                                    <td>
                                        <span class="status-pill status-<?= strtolower($repo['status']); ?>">
                                            <?= e(ucfirst($repo['status'])); ?>
                                        </span>
                                    </td>
                                    <td><code><?= e($repo['doi_handle'] ?: 'Pending DOI'); ?></code></td>
                                    <td>
                                        <small><?= e(ucfirst($repo['preservation_status'] ?: 'Ingested')); ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: PRESERVATION & BACKUPS -->
        <?php if ($activeTab === 'preservation'): ?>
            <div class="content-card" style="margin-bottom: 1.5rem;">
                <h2 class="section-title">Archival Preservation Integrity Records</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Dataset Title</th>
                                <th>Owner</th>
                                <th>Preservation Format</th>
                                <th>Status</th>
                                <th>Checksum Verified</th>
                                <th>Preserved Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preservationList as $pr): ?>
                                <tr>
                                    <td><strong><?= e($pr['dataset_title']); ?></strong></td>
                                    <td><?= e($pr['first_name'] . ' ' . $pr['last_name']); ?></td>
                                    <td><code><?= e($pr['preservation_format'] ?: 'BAGIT/ZIP'); ?></code></td>
                                    <td><span class="status-pill status-active"><?= e(ucfirst($pr['preservation_status'])); ?></span></td>
                                    <td>
                                        <?php if ($pr['checksum_verified']): ?>
                                            <span style="color: #059669; font-weight: 700;">✓ Verified</span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= date('M d, Y H:i', strtotime($pr['created_at'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="content-card">
                <h2 class="section-title">Database Backup Snapshots</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Backup File Name</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th>Created Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backupsList as $b): ?>
                                <tr>
                                    <td><code><?= e($b['filename']); ?></code></td>
                                    <td><?= number_format(((int)$b['file_size']) / 1024 / 1024, 2); ?> MB</td>
                                    <td><span class="status-pill status-active"><?= e(ucfirst($b['status'])); ?></span></td>
                                    <td><small><?= date('M d, Y H:i', strtotime($b['created_at'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: AUDIT LOGS -->
        <?php if ($activeTab === 'audit'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Institutional Audit Log Activity Center</h2>
                    <span class="readonly-indicator">Read-Only Audit Trail</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Actor</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Description / Narrative</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogsList as $log): ?>
                                <tr>
                                    <td><small><?= date('M d, Y H:i:s', strtotime($log['timestamp'])); ?></small></td>
                                    <td>
                                        <strong><?= $log['first_name'] ? e($log['first_name'] . ' ' . $log['last_name']) : 'System/Anonymous'; ?></strong>
                                    </td>
                                    <td><small><?= e(ucfirst($log['role_name'] ?: 'System')); ?></small></td>
                                    <td><code><?= e($log['action']); ?></code></td>
                                    <td><small><?= e($log['entity_type']); ?> #<?= (int)$log['entity_id']; ?></small></td>
                                    <td><?= e(mb_strimwidth($log['description'] ?? '', 0, 75, '...')); ?></td>
                                    <td><small><?= e($log['ip_address']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: SECURITY EVENTS -->
        <?php if ($activeTab === 'security'): ?>
            <div class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h2 class="section-title" style="margin: 0;">Security Events & Permission Denials</h2>
                    <span class="readonly-indicator">Security Oversight</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="data-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Event Type</th>
                                <th>Narrative / Exception Details</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($securityLogsList as $sec): ?>
                                <tr>
                                    <td><small><?= date('M d, Y H:i:s', strtotime($sec['timestamp'])); ?></small></td>
                                    <td><?= $sec['first_name'] ? e($sec['first_name'] . ' ' . $sec['last_name']) : 'Anonymous User'; ?></td>
                                    <td><span style="color: #dc2626; font-weight: 700;"><?= e($sec['action']); ?></span></td>
                                    <td><?= e($sec['description']); ?></td>
                                    <td><small><?= e($sec['ip_address']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($securityLogsList)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No security violation events recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB CONTENT: SYSTEM HEALTH -->
        <?php if ($activeTab === 'system_health'): ?>
            <div class="content-card">
                <h2 class="section-title">Institutional System Health & Subsystem Indicators</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                    <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: var(--radius-sm);">
                        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Database Connectivity</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #059669; margin-top: 0.25rem;">
                            ✓ <?= e($systemHealth['database_status']); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">MySQL PDO Engine Operational</div>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: var(--radius-sm);">
                        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Storage Upload Directory</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #059669; margin-top: 0.25rem;">
                            ✓ <?= e($systemHealth['upload_dir_writable']); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">Directory Write Permissions Active</div>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.25rem; border-radius: var(--radius-sm);">
                        <div style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">PHP Runtime</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color); margin-top: 0.25rem;">
                            PHP <?= e($systemHealth['php_version']); ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.5rem;">All Required Extensions Loaded</div>
                    </div>
                </div>

                <h3 style="margin-top: 2rem; font-size: 1.1rem; color: var(--primary-color);">Required Extensions Checklist</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem;">
                    <?php foreach ($systemHealth['extensions'] as $ext => $status): ?>
                        <div style="background: #ffffff; border: 1px solid var(--border-color); padding: 0.75rem 1rem; border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: center;">
                            <strong><?= e(strtoupper($ext)); ?></strong>
                            <?php if ($status): ?>
                                <span style="color: #059669; font-weight: 700;">✓ Enabled</span>
                            <?php else: ?>
                                <span style="color: #dc2626; font-weight: 700;">✗ Missing</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
