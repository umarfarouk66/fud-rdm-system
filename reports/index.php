<?php
/**
 * Institutional Reporting & Analytics Overview Center
 * FUD RDM System - Phase 10: Institutional Reports & Analytics
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/reports_helpers.php';

// Allow ALL authenticated system roles (scoped appropriately server-side)
requireAuth();

$user       = currentUser();
$userId     = (int)$user['id'];
$systemRole = strtolower($user['role'] ?? 'researcher');
$isSuper    = isSuperAdmin();

// Get role-based SQL scope
$scope = getReportingScope($userId, $systemRole);

// Filter Parameters
$deptFilter    = trim($_GET['department'] ?? '');
$sessionFilter = trim($_GET['session'] ?? '');
$activeTab     = trim($_GET['tab'] ?? 'overview');

// Calculate Institutional KPIs
$kpis = getInstitutionalKpiMetrics($pdo, $scope);

// Fetch Supervisor Workload Data
$workloadData = ($systemRole === 'admin' || $systemRole === 'librarian' || $systemRole === 'super_admin') 
    ? getSupervisorWorkloadAnalytics($pdo, ['department' => $deptFilter]) 
    : [];

// Fetch Unassigned Students Data
$unassignedData = ($systemRole === 'admin' || $systemRole === 'librarian' || $systemRole === 'super_admin') 
    ? getUnassignedStudentsReport($pdo, ['department' => $deptFilter]) 
    : [];

// Distinct Departments for filter dropdown
$deptStmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departmentsList = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Reports & Analytics — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            border-radius: var(--radius-sm);
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-num {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1.1;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table th, .data-table td {
            padding: 0.85rem 0.95rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .badge-read-only {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .tab-btn {
            padding: 0.6rem 1.25rem;
            font-weight: 700;
            font-size: 0.85rem;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: inline-block;
        }
        .tab-btn.active {
            background: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="../auth/login.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-home-nav" style="text-decoration: none; color: #1e3a8a; display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 700; background: #ffffff; padding: 0.45rem 0.9rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); font-size: 0.85rem; margin-right: 0.5rem;" title="Go to Home Page">
                <span>🏠 Home</span>
            </a>
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
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: 'Federal University Dutse'); ?></div>
                </div>
                <?php if ($isSuper): ?>
                    <span class="role-badge role-badge-super-admin" style="background: #7c3aed; color: #fff;">Super Admin (Read-Only)</span>
                <?php else: ?>
                    <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e(ucfirst($systemRole)); ?></span>
                <?php endif; ?>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="javascript:history.back()" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Return to Dashboard
            </a>
            <?php if ($isSuper): ?>
                <span class="badge-read-only">👁️ Super Admin Read-Only Reporting</span>
            <?php endif; ?>
        </div>

        <!-- Page Header & Export Buttons -->
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                    📊 Institutional Reporting & Analytics Dashboard
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Monitor university-wide research project progression, supervision workloads, defense viva results, and repository publication pipelines.
                </p>
            </div>

            <!-- Export Buttons & Generate Report Action -->
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <a href="generate.php" class="btn-primary" style="padding: 0.55rem 1.25rem; font-size: 0.85rem; font-weight: 700; background: #2563eb; text-decoration: none; box-shadow: var(--shadow-sm);">
                    ⚡ Generate Report Console &rarr;
                </a>
                <a href="export.php?report=projects&format=csv" class="btn-primary" style="padding: 0.55rem 1rem; font-size: 0.8rem; font-weight: 700; background: #059669; text-decoration: none;">
                    📊 Export Projects (CSV)
                </a>
                <a href="export.php?report=supervisors_workload&format=csv" class="btn-primary" style="padding: 0.55rem 1rem; font-size: 0.8rem; font-weight: 700; background: #0891b2; text-decoration: none;">
                    👨‍🏫 Export Workload (CSV)
                </a>
                <a href="export.php?report=projects&format=print" target="_blank" class="btn-logout" style="padding: 0.55rem 1rem; font-size: 0.8rem; font-weight: 700; border: 1px solid var(--border-color); background: #fff; text-decoration: none;">
                    🖨️ Print Report
                </a>
            </div>
        </div>

        <!-- High-Level Institutional KPI Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div>
                    <div class="stat-num"><?= $kpis['total_students']; ?></div>
                    <div class="stat-label">Total Active Students (<?= $kpis['unassigned_students']; ?> Unassigned)</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">👨‍🏫</div>
                <div>
                    <div class="stat-num"><?= $kpis['total_supervisors']; ?></div>
                    <div class="stat-label">Active Faculty Supervisors</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📂</div>
                <div>
                    <div class="stat-num"><?= $kpis['active_projects']; ?></div>
                    <div class="stat-label">Active Research Projects</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div>
                    <div class="stat-num" style="color: #d97706;"><?= $kpis['awaiting_supervisor_review']; ?></div>
                    <div class="stat-label">Submissions Pending Review</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🎓</div>
                <div>
                    <div class="stat-num" style="color: #1e40af;"><?= $kpis['scheduled_defenses']; ?></div>
                    <div class="stat-label">Scheduled Defenses</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div>
                    <div class="stat-num" style="color: #059669;"><?= $kpis['published_projects']; ?></div>
                    <div class="stat-label">Repository Published Projects</div>
                </div>
            </div>
        </div>

        <!-- Specialized Report Modules Grid -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm);">
            <h3 style="font-size: 1.05rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                📋 Specialized Institutional & Repository Reports
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
                <a href="datasets.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; text-decoration: none; color: inherit; font-weight: 700; transition: border-color 0.2s;">
                    <div style="font-size: 1.25rem; margin-bottom: 0.25rem;">📊</div>
                    <div style="font-size: 0.85rem; color: var(--primary-color);">Dataset Analytics & Quality</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.2rem;">Metadata & storage metrics</div>
                </a>
                <a href="projects.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; text-decoration: none; color: inherit; font-weight: 700; transition: border-color 0.2s;">
                    <div style="font-size: 1.25rem; margin-bottom: 0.25rem;">📁</div>
                    <div style="font-size: 0.85rem; color: var(--primary-color);">Research Projects Report</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.2rem;">Project completion status</div>
                </a>
                <a href="preservation.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; text-decoration: none; color: inherit; font-weight: 700; transition: border-color 0.2s;">
                    <div style="font-size: 1.25rem; margin-bottom: 0.25rem;">🛡️</div>
                    <div style="font-size: 0.85rem; color: var(--primary-color);">Digital Preservation Report</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.2rem;">Checksum & archival audits</div>
                </a>
                <a href="access.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; text-decoration: none; color: inherit; font-weight: 700; transition: border-color 0.2s;">
                    <div style="font-size: 1.25rem; margin-bottom: 0.25rem;">🔑</div>
                    <div style="font-size: 0.85rem; color: var(--primary-color);">Access Requests Report</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.2rem;">Data access & governance</div>
                </a>
                <a href="activity.php" style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem; text-decoration: none; color: inherit; font-weight: 700; transition: border-color 0.2s;">
                    <div style="font-size: 1.25rem; margin-bottom: 0.25rem;">📋</div>
                    <div style="font-size: 0.85rem; color: var(--primary-color);">Repository Activity Logs</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal; margin-top: 0.2rem;">Views & download statistics</div>
                </a>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; border-bottom: 2px solid var(--border-color); padding-bottom: 0.75rem;">
            <a href="index.php?tab=overview" class="tab-btn <?= $activeTab === 'overview' ? 'active' : ''; ?>">📊 Institutional Overview</a>
            <?php if (in_array($systemRole, ['admin', 'librarian', 'super_admin'], true)): ?>
                <a href="index.php?tab=workload" class="tab-btn <?= $activeTab === 'workload' ? 'active' : ''; ?>">👨‍🏫 Supervisor Workload (<?= count($workloadData); ?>)</a>
                <a href="index.php?tab=unassigned" class="tab-btn <?= $activeTab === 'unassigned' ? 'active' : ''; ?>">⚠️ Unassigned Students (<?= count($unassignedData); ?>)</a>
            <?php endif; ?>
        </div>

        <!-- TAB 1: OVERVIEW & STAGE BREAKDOWN -->
        <?php if ($activeTab === 'overview'): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                
                <!-- Lifecycle Stage Visual Breakdown -->
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                        🔄 Research Lifecycle Stage Distribution
                    </h3>
                    <?= renderBarVisual('Active Projects', $kpis['active_projects'], $kpis['total_projects'], '#3b82f6'); ?>
                    <?= renderBarVisual('Approved for Defense', $kpis['approved_for_defense'], $kpis['total_projects'], '#8b5cf6'); ?>
                    <?= renderBarVisual('Completed Defenses', $kpis['completed_defenses'], $kpis['total_projects'], '#059669'); ?>
                    <?= renderBarVisual('Repository Published', $kpis['published_projects'], $kpis['total_projects'], '#10b981'); ?>
                </div>

                <!-- Submissions & Corrections Analytics -->
                <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                        🛠️ Supervision & Review Pipeline
                    </h3>
                    <?= renderBarVisual('Pending Supervisor Reviews', $kpis['awaiting_supervisor_review'], max(1, $kpis['active_projects']), '#d97706'); ?>
                    <?= renderBarVisual('Open Correction Items', $kpis['open_corrections'], max(1, $kpis['active_projects']), '#dc2626'); ?>
                    <?= renderBarVisual('Awaiting Repository Curation', $kpis['pending_repository_review'], max(1, $kpis['completed_projects']), '#4f46e5'); ?>
                    <?= renderBarVisual('Archived Records', $kpis['archived_projects'], max(1, $kpis['completed_projects']), '#64748b'); ?>
                </div>

            </div>
        <?php endif; ?>

        <!-- TAB 2: SUPERVISOR WORKLOAD ANALYTICS -->
        <?php if ($activeTab === 'workload' && !empty($workloadData)): ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin: 0;">
                        👨‍🏫 Faculty Supervisor Workload & Performance Registry (<?= count($workloadData); ?>)
                    </h3>
                    <a href="export.php?report=supervisors_workload&format=csv" style="font-size: 0.8rem; font-weight: 700; color: var(--accent-color); text-decoration: none;">
                        Export Workload CSV &rarr;
                    </a>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Faculty Supervisor</th>
                                <th>Department</th>
                                <th>Assigned Students</th>
                                <th>Active Projects</th>
                                <th>Pending Reviews</th>
                                <th>Open Corrections</th>
                                <th>Overdue Milestones</th>
                                <th>Workload Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($workloadData as $wRow): 
                                $assigned = (int)$wRow['assigned_students_count'];
                                $isHigh   = ($assigned >= 5);
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-color);">
                                            Prof./Dr. <?= e($wRow['first_name'] . ' ' . $wRow['last_name']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($wRow['email']); ?></div>
                                    </td>
                                    <td><?= e($wRow['department'] ?: 'N/A'); ?></td>
                                    <td style="font-weight: 800; font-size: 0.95rem; color: var(--primary-color);"><?= $assigned; ?></td>
                                    <td><?= (int)$wRow['active_projects_count']; ?></td>
                                    <td style="color: #d97706; font-weight: 700;"><?= (int)$wRow['pending_reviews_count']; ?></td>
                                    <td style="color: #dc2626; font-weight: 700;"><?= (int)$wRow['open_corrections_count']; ?></td>
                                    <td style="color: #b91c1c; font-weight: 700;"><?= (int)$wRow['overdue_milestones_count']; ?></td>
                                    <td>
                                        <?php if ($isHigh): ?>
                                            <span style="background: #fef3c7; color: #92400e; padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                                ⚠️ High Load (<?= $assigned; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span style="background: #dcfce7; color: #15803d; padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                                ✅ Normal (<?= $assigned; ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- TAB 3: UNASSIGNED STUDENTS REPORT -->
        <?php if ($activeTab === 'unassigned' && !empty($unassignedData)): ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1.15rem; color: #b91c1c; font-weight: 700; margin: 0;">
                        ⚠️ Unassigned Active Student Researchers (<?= count($unassignedData); ?>)
                    </h3>
                    <?php if ($systemRole === 'admin'): ?>
                        <a href="../admin/assign_supervisor.php" class="btn-primary" style="font-size: 0.8rem; font-weight: 700; padding: 0.4rem 0.85rem; text-decoration: none;">
                            ➕ Assign Supervisor Console &rarr;
                        </a>
                    <?php endif; ?>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Researcher</th>
                                <th>Matric Number</th>
                                <th>Department & Faculty</th>
                                <th>Programme</th>
                                <th>Research Project</th>
                                <th>Date Registered</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unassignedData as $uRow): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-color);">
                                            <?= e($uRow['first_name'] . ' ' . $uRow['last_name']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($uRow['email']); ?></div>
                                    </td>
                                    <td style="font-family: monospace; font-weight: 700;"><?= e($uRow['matric_number'] ?: 'N/A'); ?></td>
                                    <td><?= e($uRow['department']); ?> (<?= e($uRow['faculty']); ?>)</td>
                                    <td><?= e($uRow['programme_name'] ?: 'N/A'); ?></td>
                                    <td>
                                        <?php if ($uRow['project_id']): ?>
                                            <a href="../projects/view.php?id=<?= $uRow['project_id']; ?>" style="font-weight: 700; color: var(--primary-color); text-decoration: none;">
                                                <?= e($uRow['project_title']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="font-style: italic; color: var(--text-muted);">No project yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 0.8rem;"><?= date('M d, Y', strtotime($uRow['date_registered'])); ?></td>
                                    <td>
                                        <?php if ($systemRole === 'admin'): ?>
                                            <a href="../admin/assign_supervisor.php?student_id=<?= $uRow['student_id']; ?>" class="btn-sm" style="background: #4f46e5; color: #fff; border: none; padding: 0.25rem 0.65rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; text-decoration: none;">
                                                Assign Supervisor
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Read-Only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
