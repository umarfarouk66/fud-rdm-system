<?php
/**
 * Librarian & Institutional Report Generator Console
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

// Server-side authorization check: Admin, Librarian, Super Admin
requireRole(['admin', 'librarian']);

$user       = currentUser();
$userId     = (int)$user['id'];
$systemRole = strtolower($user['role'] ?? 'researcher');
$isSuper    = isSuperAdmin();

// Extract Filter Parameters
$reportType = strtolower(trim($_GET['report'] ?? 'projects'));
$filters = [
    'department'    => trim($_GET['department'] ?? ''),
    'programme'     => trim($_GET['programme'] ?? ''),
    'session'       => trim($_GET['session'] ?? ''),
    'status'        => trim($_GET['status'] ?? 'all'),
    'date_preset'   => trim($_GET['date_preset'] ?? 'all'),
    'start_date'    => trim($_GET['start_date'] ?? ''),
    'end_date'      => trim($_GET['end_date'] ?? ''),
    'supervisor_id' => (int)($_GET['supervisor_id'] ?? 0)
];

// Fetch Dropdown Option Lists
$deptStmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$departmentsList = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

$progStmt = $pdo->query("SELECT id, name FROM programmes ORDER BY name ASC");
$programmesList = $progStmt->fetchAll(PDO::FETCH_ASSOC);

$supStmt = $pdo->query("
    SELECT u.id, u.first_name, u.last_name, u.department 
    FROM users u 
    INNER JOIN roles r ON u.role_id = r.id AND r.name = 'supervisor' 
    WHERE u.status = 'active' 
    ORDER BY u.last_name ASC
");
$supervisorsList = $supStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Role-Based Scope & Generated Report Dataset
$scope      = getReportingScope($userId, $systemRole);
$reportData = getFilteredReportData($pdo, $reportType, $filters, $scope);

$reportTitle = $reportData['report_title'];
$headers     = $reportData['headers'];
$rows        = $reportData['rows'];
$rowCount    = $reportData['count'];

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);

// Build Query String for Export Links
$exportQueryParams = http_build_query(array_merge(['report' => $reportType], $filters));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Generator Console — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.35rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-control-select, .form-control-input {
            width: 100%;
            padding: 0.55rem 0.75rem;
            font-size: 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: #f8fafc;
            color: var(--text-main);
        }
        .form-control-select:focus, .form-control-input:focus {
            outline: none;
            border-color: var(--accent-color);
            background: #ffffff;
        }
        .report-preview-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .export-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 0.85rem 1.25rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1rem;
            font-size: 0.85rem;
            font-weight: 700;
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn-export:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }
        .btn-export-pdf { background: #1e3a8a; color: #ffffff; }
        .btn-export-csv { background: #059669; color: #ffffff; }
        .btn-export-txt { background: #475569; color: #ffffff; }
        .btn-export-json { background: #7c3aed; color: #ffffff; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .data-table th {
            background: #f8fafc;
            padding: 0.75rem 0.9rem;
            border-bottom: 2px solid var(--border-color);
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            text-align: left;
        }
        .data-table td {
            padding: 0.75rem 0.9rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .filter-chip {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-right: 0.35rem;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="index.php" class="dash-brand">
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
                    <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                </div>
                <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e(ucfirst($systemRole)); ?></span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Return to Reporting Hub
            </a>
            <?php if ($isSuper): ?>
                <span style="background: #fef3c7; color: #92400e; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                    👁️ Super Admin Read-Only Reporting Mode
                </span>
            <?php endif; ?>
        </div>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem; display: flex; align-items: center; gap: 0.5rem;">
                ⚡ Interactive Institutional Report Generator Console
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Select report parameters, apply custom department/session/status filters, preview generated institutional outputs, and export to PDF/Print or CSV.
            </p>
        </div>

        <!-- REPORT GENERATION CONTROLS FORM -->
        <div class="filter-panel">
            <form action="generate.php" method="GET">
                <div class="filter-grid">
                    
                    <!-- Report Type Dropdown -->
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="report">📋 Select Report Type</label>
                        <select name="report" id="report" class="form-control-select" style="font-weight: 700;">
                            <optgroup label="📂 Research Projects Reports">
                                <option value="projects" <?= $reportType === 'projects' ? 'selected' : ''; ?>>📁 All Research Projects Report</option>
                                <option value="completed_projects" <?= $reportType === 'completed_projects' ? 'selected' : ''; ?>>✅ Completed Research Projects Report</option>
                                <option value="published_projects" <?= $reportType === 'published_projects' ? 'selected' : ''; ?>>🎓 Published Repository Projects Report</option>
                                <option value="archived_projects" <?= $reportType === 'archived_projects' ? 'selected' : ''; ?>>📦 Archived Research Projects Report</option>
                                <option value="projects_by_department" <?= $reportType === 'projects_by_department' ? 'selected' : ''; ?>>🏛️ Projects by Department Report</option>
                                <option value="projects_by_session" <?= $reportType === 'projects_by_session' ? 'selected' : ''; ?>>📅 Projects by Academic Session Report</option>
                            </optgroup>
                            <optgroup label="📚 Repository & Data Management Reports">
                                <option value="datasets" <?= $reportType === 'datasets' ? 'selected' : ''; ?>>📊 Repository Collections & Datasets Report</option>
                                <option value="metadata_quality" <?= $reportType === 'metadata_quality' ? 'selected' : ''; ?>>🏷️ Dataset Metadata Quality Report</option>
                                <option value="citation_pids" <?= $reportType === 'citation_pids' ? 'selected' : ''; ?>>🔗 Citation & PID Allocation Report</option>
                                <option value="preservation" <?= $reportType === 'preservation' ? 'selected' : ''; ?>>🛡️ Digital Preservation & Archival Quality Report</option>
                                <option value="activity" <?= $reportType === 'activity' ? 'selected' : ''; ?>>📋 Repository Usage & Activity Logs Report</option>
                                <option value="repository_publication" <?= $reportType === 'repository_publication' ? 'selected' : ''; ?>>🌐 Repository Integration & Publication Pipeline</option>
                            </optgroup>
                            <optgroup label="👥 Governance & Supervision Reports">
                                <option value="access" <?= $reportType === 'access' ? 'selected' : ''; ?>>🔑 Access Requests Governance Report</option>
                                <option value="supervisors_workload" <?= $reportType === 'supervisors_workload' ? 'selected' : ''; ?>>👨‍🏫 Faculty Supervisor Workload Report</option>
                                <option value="unassigned_students" <?= $reportType === 'unassigned_students' ? 'selected' : ''; ?>>⚠️ Unassigned Active Students Report</option>
                                <option value="defense_viva" <?= $reportType === 'defense_viva' ? 'selected' : ''; ?>>🎓 Oral Defense & Viva Examination Report</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Department Filter -->
                    <div class="form-group">
                        <label for="department">🏛️ Department</label>
                        <select name="department" id="department" class="form-control-select">
                            <option value="">-- All Departments --</option>
                            <?php foreach ($departmentsList as $dName): ?>
                                <option value="<?= e($dName); ?>" <?= $filters['department'] === $dName ? 'selected' : ''; ?>>
                                    <?= e($dName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Programme Filter -->
                    <div class="form-group">
                        <label for="programme">🎓 Programme</label>
                        <select name="programme" id="programme" class="form-control-select">
                            <option value="">-- All Programmes --</option>
                            <?php foreach ($programmesList as $pProg): ?>
                                <option value="<?= (int)$pProg['id']; ?>" <?= (int)$filters['programme'] === (int)$pProg['id'] ? 'selected' : ''; ?>>
                                    <?= e($pProg['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Academic Session Filter -->
                    <div class="form-group">
                        <label for="session">📅 Academic Session</label>
                        <select name="session" id="session" class="form-control-select">
                            <option value="">-- All Sessions --</option>
                            <option value="2026" <?= $filters['session'] === '2026' ? 'selected' : ''; ?>>2025/2026 Session</option>
                            <option value="2025" <?= $filters['session'] === '2025' ? 'selected' : ''; ?>>2024/2025 Session</option>
                            <option value="2024" <?= $filters['session'] === '2024' ? 'selected' : ''; ?>>2023/2024 Session</option>
                        </select>
                    </div>

                    <!-- Project / Dataset Status Filter -->
                    <div class="form-group">
                        <label for="status">📌 Status</label>
                        <select name="status" id="status" class="form-control-select">
                            <option value="all" <?= $filters['status'] === 'all' ? 'selected' : ''; ?>>-- All Statuses --</option>
                            <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : ''; ?>>Draft / Planning</option>
                            <option value="in_progress" <?= $filters['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="submitted" <?= $filters['status'] === 'submitted' ? 'selected' : ''; ?>>Submitted / Under Review</option>
                            <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : ''; ?>>Completed / Approved</option>
                            <option value="published" <?= $filters['status'] === 'published' ? 'selected' : ''; ?>>Published in Repository</option>
                            <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>

                    <!-- Date Range Preset -->
                    <div class="form-group">
                        <label for="date_preset">⏱️ Date Range Preset</label>
                        <select name="date_preset" id="date_preset" class="form-control-select" onchange="toggleCustomDateInputs(this.value)">
                            <option value="all" <?= $filters['date_preset'] === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="today" <?= $filters['date_preset'] === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="7days" <?= $filters['date_preset'] === '7days' ? 'selected' : ''; ?>>Last 7 Days</option>
                            <option value="30days" <?= $filters['date_preset'] === '30days' ? 'selected' : ''; ?>>Last 30 Days</option>
                            <option value="90days" <?= $filters['date_preset'] === '90days' ? 'selected' : ''; ?>>Last 90 Days</option>
                            <option value="thisyear" <?= $filters['date_preset'] === 'thisyear' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?= $filters['date_preset'] === 'custom' ? 'selected' : ''; ?>>Custom Date Range</option>
                        </select>
                    </div>

                    <!-- Custom Start Date -->
                    <div class="form-group custom-date-group" style="display: <?= $filters['date_preset'] === 'custom' ? 'block' : 'none'; ?>;">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control-input" value="<?= e($filters['start_date']); ?>">
                    </div>

                    <!-- Custom End Date -->
                    <div class="form-group custom-date-group" style="display: <?= $filters['date_preset'] === 'custom' ? 'block' : 'none'; ?>;">
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control-input" value="<?= e($filters['end_date']); ?>">
                    </div>

                    <!-- Supervisor Filter -->
                    <div class="form-group">
                        <label for="supervisor_id">👨‍🏫 Faculty Supervisor</label>
                        <select name="supervisor_id" id="supervisor_id" class="form-control-select">
                            <option value="0">-- All Supervisors --</option>
                            <?php foreach ($supervisorsList as $sup): ?>
                                <option value="<?= (int)$sup['id']; ?>" <?= (int)$filters['supervisor_id'] === (int)$sup['id'] ? 'selected' : ''; ?>>
                                    Prof./Dr. <?= e($sup['first_name'] . ' ' . $sup['last_name']); ?> (<?= e($sup['department']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                    <a href="generate.php" class="btn-logout" style="padding: 0.6rem 1.25rem; font-size: 0.85rem; font-weight: 700; text-decoration: none; border: 1px solid var(--border-color); background: #fff;">
                        Reset Filters
                    </a>
                    <button type="submit" class="btn-primary" style="padding: 0.6rem 1.75rem; font-size: 0.9rem; font-weight: 700; background: var(--primary-color);">
                        ⚡ Generate Report
                    </button>
                </div>
            </form>
        </div>

        <!-- GENERATED REPORT PREVIEW PANEL -->
        <div class="report-preview-panel">
            
            <!-- Institutional Header & Metadata -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--border-color); padding-bottom: 1.25rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-size: 1.4rem; color: var(--primary-color); font-weight: 800; margin-bottom: 0.35rem; display: flex; align-items: center; gap: 0.5rem;">
                        📊 <?= e($reportTitle); ?>
                    </h2>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        <strong>Institution:</strong> Federal University Dutse &bull; <strong>System:</strong> Research Data & Repository Infrastructure
                    </div>
                    <div style="margin-top: 0.5rem;">
                        <?php if (!empty($filters['department'])): ?><span class="filter-chip">🏛️ Dept: <?= e($filters['department']); ?></span><?php endif; ?>
                        <?php if (!empty($filters['session'])): ?><span class="filter-chip">📅 Session: <?= e($filters['session']); ?></span><?php endif; ?>
                        <?php if (!empty($filters['status']) && $filters['status'] !== 'all'): ?><span class="filter-chip">📌 Status: <?= e(ucfirst($filters['status'])); ?></span><?php endif; ?>
                        <?php if (!empty($filters['date_preset']) && $filters['date_preset'] !== 'all'): ?><span class="filter-chip">⏱️ Date: <?= e($filters['date_preset']); ?></span><?php endif; ?>
                        <?php if (empty($filters['department']) && empty($filters['session']) && ($filters['status']==='all'||empty($filters['status']))): ?>
                            <span class="filter-chip" style="background: #dcfce7; color: #166534;">Scope: Institution-Wide (All Departments)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="text-align: right; font-size: 0.85rem; color: var(--text-muted);">
                    <div><strong>Generated At:</strong> <?= date('M d, Y H:i:s'); ?></div>
                    <div><strong>Generated By:</strong> <?= e($user['name']); ?> (<?= e(ucfirst($systemRole)); ?>)</div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: var(--primary-color); margin-top: 0.35rem;">
                        Total Records: <?= $rowCount; ?>
                    </div>
                </div>
            </div>

            <!-- EXPORT ACTION BAR -->
            <div class="export-bar">
                <div style="font-weight: 700; color: var(--primary-color); font-size: 0.9rem;">
                    💾 Export Generated Report (Filtered Result):
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <a href="export.php?format=print&<?= $exportQueryParams; ?>" target="_blank" class="btn-export btn-export-pdf">
                        📄 Export as PDF / Print
                    </a>
                    <a href="export.php?format=csv&<?= $exportQueryParams; ?>" class="btn-export btn-export-csv">
                        📊 Export as CSV
                    </a>
                    <a href="export.php?format=txt&<?= $exportQueryParams; ?>" class="btn-export btn-export-txt">
                        📄 Export as Text
                    </a>
                    <a href="export.php?format=json&<?= $exportQueryParams; ?>" class="btn-export btn-export-json">
                        💻 Export as JSON
                    </a>
                </div>
            </div>

            <!-- REPORT DATA TABLE PREVIEW -->
            <?php if ($rowCount === 0): ?>
                <div style="padding: 3rem; text-align: center; color: var(--text-muted); font-size: 0.95rem; border: 2px dashed var(--border-color); border-radius: var(--radius-sm);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🔍</div>
                    <strong>No matching records found.</strong>
                    <p style="font-size: 0.85rem; margin-top: 0.25rem;">Try adjusting your selected filters or choosing "All Time" date range.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                    <th><?= e($h); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ((array)$row as $val): ?>
                                        <td><?= e($val); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

    <script>
        function toggleCustomDateInputs(val) {
            const customGroups = document.querySelectorAll('.custom-date-group');
            customGroups.forEach(el => {
                el.style.display = (val === 'custom') ? 'block' : 'none';
            });
        }
    </script>
</body>
</html>
