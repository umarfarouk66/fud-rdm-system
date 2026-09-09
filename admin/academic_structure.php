<?php
/**
 * Academic Structure Management Console
 * FUD RDM & Project Supervision System - Step 15 (Phase 2)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/admin_helpers.php';

// Enforce administrator role (Super Admin gets read-only view)
requireRole('admin');

$adminUser = currentUser();
$adminId   = (int)$adminUser['id'];

$feedbackMessage = $_SESSION['admin_success'] ?? '';
$errorMessage    = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Handle Form Submissions (Create Faculty, Department, Programme, Academic Session)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission(); // Server-side read-only guard for Super Admin

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (invalid CSRF token).';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Create Faculty
        if ($action === 'create_faculty') {
            $name = sanitize_input($_POST['faculty_name'] ?? '');
            $code = strtoupper(sanitize_input($_POST['faculty_code'] ?? ''));

            if (empty($name) || empty($code)) {
                $errorMessage = 'Please provide both Faculty Name and Code.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO faculties (name, code) VALUES (:name, :code)");
                    $stmt->execute([':name' => $name, ':code' => $code]);

                    log_audit(
                        $pdo,
                        'faculty_created',
                        'faculties',
                        (int)$pdo->lastInsertId(),
                        "Administrator '{$adminUser['name']}' created faculty '{$name}' ({$code})"
                    );
                    $feedbackMessage = "Faculty '{$name}' created successfully.";
                } catch (PDOException $e) {
                    $errorMessage = 'Faculty Name or Code already exists.';
                }
            }
        }
        // 2. Create Department
        elseif ($action === 'create_department') {
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            $name      = sanitize_input($_POST['dept_name'] ?? '');
            $code      = strtoupper(sanitize_input($_POST['dept_code'] ?? ''));

            if ($facultyId <= 0 || empty($name) || empty($code)) {
                $errorMessage = 'Please select a valid Faculty and enter Department Name and Code.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO departments (faculty_id, name, code) VALUES (:fid, :name, :code)");
                    $stmt->execute([':fid' => $facultyId, ':name' => $name, ':code' => $code]);

                    log_audit(
                        $pdo,
                        'department_created',
                        'departments',
                        (int)$pdo->lastInsertId(),
                        "Administrator '{$adminUser['name']}' created department '{$name}' ({$code})"
                    );
                    $feedbackMessage = "Department '{$name}' created successfully.";
                } catch (PDOException $e) {
                    $errorMessage = 'Department Name or Code already exists in selected Faculty.';
                }
            }
        }
        // 3. Create Programme
        elseif ($action === 'create_programme') {
            $deptId = (int)($_POST['department_id'] ?? 0);
            $name   = sanitize_input($_POST['prog_name'] ?? '');
            $level  = strtolower(sanitize_input($_POST['degree_level'] ?? 'undergraduate'));

            if ($deptId <= 0 || empty($name)) {
                $errorMessage = 'Please select a valid Department and enter Programme Name.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO programmes (department_id, name, degree_level) VALUES (:did, :name, :level)");
                    $stmt->execute([':did' => $deptId, ':name' => $name, ':level' => $level]);

                    log_audit(
                        $pdo,
                        'programme_created',
                        'programmes',
                        (int)$pdo->lastInsertId(),
                        "Administrator '{$adminUser['name']}' created programme '{$name}'"
                    );
                    $feedbackMessage = "Degree Programme '{$name}' created successfully.";
                } catch (PDOException $e) {
                    $errorMessage = 'Failed to create programme: ' . $e->getMessage();
                }
            }
        }
        // 4. Create Academic Session
        elseif ($action === 'create_session') {
            $sessionName = sanitize_input($_POST['session_name'] ?? '');
            $isCurrent   = !empty($_POST['is_current']);
            $startDate   = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $endDate     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

            if (empty($sessionName)) {
                $errorMessage = 'Please enter an Academic Session name (e.g. 2025/2026).';
            } else {
                try {
                    if ($isCurrent) {
                        $pdo->exec("UPDATE academic_sessions SET is_current = FALSE");
                    }
                    $stmt = $pdo->prepare("INSERT INTO academic_sessions (session_name, is_current, start_date, end_date) VALUES (:name, :curr, :sdate, :edate)");
                    $stmt->execute([
                        ':name' => $sessionName,
                        ':curr' => $isCurrent ? 1 : 0,
                        ':sdate' => $startDate,
                        ':edate' => $endDate
                    ]);

                    log_audit(
                        $pdo,
                        'academic_session_created',
                        'academic_sessions',
                        (int)$pdo->lastInsertId(),
                        "Administrator '{$adminUser['name']}' created academic session '{$sessionName}'"
                    );
                    $feedbackMessage = "Academic Session '{$sessionName}' created successfully.";
                } catch (PDOException $e) {
                    $errorMessage = 'Academic Session name already exists.';
                }
            }
        }
    }
}

// Fetch existing academic structures
$faculties = $pdo->query("SELECT * FROM faculties ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$departments = $pdo->query("
    SELECT d.*, f.name AS faculty_name, f.code AS faculty_code 
    FROM departments d
    INNER JOIN faculties f ON d.faculty_id = f.id
    ORDER BY f.name ASC, d.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$programmes = $pdo->query("
    SELECT p.*, d.name AS department_name, f.name AS faculty_name
    FROM programmes p
    INNER JOIN departments d ON p.department_id = d.id
    INNER JOIN faculties f ON d.faculty_id = f.id
    ORDER BY d.name ASC, p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$sessions = $pdo->query("SELECT * FROM academic_sessions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Structure Management — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .struct-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .struct-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .struct-header {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .form-row {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            margin-bottom: 0.85rem;
        }
        .form-row label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .form-row input, .form-row select {
            padding: 0.45rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
        }
        .struct-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 1rem;
        }
        .struct-table th {
            text-align: left;
            padding: 0.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            color: #475569;
            font-weight: 700;
        }
        .struct-table td {
            padding: 0.55rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Admin Console</div>
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
                    <div class="user-name"><?= e($adminUser['name']); ?></div>
                    <div class="user-affiliation"><?= isSuperAdmin() ? 'Institutional Monitor' : 'System Administrator'; ?></div>
                </div>
                <span class="role-badge role-badge-admin" style="<?= isSuperAdmin() ? 'background: #4338ca; color: #ffffff;' : ''; ?>">
                    <?= isSuperAdmin() ? 'Super Admin' : 'Admin'; ?>
                </span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Navigation Breadcrumb -->
        <div style="margin-bottom: 1.25rem;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Admin Dashboard
            </a>
        </div>

        <!-- Flash Notices -->
        <?php if (!empty($feedbackMessage)): ?>
            <div class="dash-alert dash-alert-success" style="margin-bottom: 1.5rem;">
                <span><?= e($feedbackMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem;">
                <span><?= e($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isSuperAdmin()): ?>
            <div class="dash-alert" style="background: #e0e7ff; border-color: #6366f1; color: #312e81; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">👁️</span>
                <div>
                    <strong>Super Admin Read-Only Mode:</strong> You are viewing academic structure configurations in monitoring mode. Form submissions are disabled server-side.
                </div>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                🏛️ University Academic Structure Governance
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Manage Federal University Dutse faculties, departments, degree programmes and academic session calendars.
            </p>
        </div>

        <!-- Structure Grid -->
        <div class="struct-grid">

            <!-- 1. Faculties Card -->
            <div class="struct-card">
                <div class="struct-header">
                    <span>🏢 Institutional Faculties</span>
                    <span style="font-size: 0.8rem; background: #e0e7ff; color: #3730a3; padding: 0.15rem 0.5rem; border-radius: 9999px;"><?= count($faculties); ?> Active</span>
                </div>

                <?php if (!isSuperAdmin()): ?>
                    <form method="POST" action="academic_structure.php" style="margin-bottom: 1.25rem;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_faculty">

                        <div style="display: flex; gap: 0.5rem;">
                            <input type="text" name="faculty_name" placeholder="Faculty Name (e.g. Faculty of Science)" required style="flex: 2; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem;">
                            <input type="text" name="faculty_code" placeholder="Code (e.g. FSC)" required style="flex: 1; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem; text-transform: uppercase;">
                            <button type="submit" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.45rem 0.85rem; font-size: 0.85rem; font-weight: 700; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                                Add
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <table class="struct-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Faculty Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($faculties)): ?>
                            <tr><td colspan="2" style="color: var(--text-muted); text-align: center;">No faculties configured.</td></tr>
                        <?php else: ?>
                            <?php foreach ($faculties as $f): ?>
                                <tr>
                                    <td><strong style="font-family: monospace; color: var(--accent-color);"><?= e($f['code']); ?></strong></td>
                                    <td><?= e($f['name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 2. Departments Card -->
            <div class="struct-card">
                <div class="struct-header">
                    <span>🏫 Academic Departments</span>
                    <span style="font-size: 0.8rem; background: #d1fae5; color: #065f46; padding: 0.15rem 0.5rem; border-radius: 9999px;"><?= count($departments); ?> Active</span>
                </div>

                <?php if (!isSuperAdmin()): ?>
                    <form method="POST" action="academic_structure.php" style="margin-bottom: 1.25rem;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_department">

                        <div class="form-row">
                            <label for="faculty_id">Belongs to Faculty *</label>
                            <select id="faculty_id" name="faculty_id" required>
                                <option value="">-- Select Parent Faculty --</option>
                                <?php foreach ($faculties as $f): ?>
                                    <option value="<?= (int)$f['id']; ?>"><?= e($f['name']); ?> (<?= e($f['code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: flex; gap: 0.5rem;">
                            <input type="text" name="dept_name" placeholder="Department Name" required style="flex: 2; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem;">
                            <input type="text" name="dept_code" placeholder="Code (e.g. CSC)" required style="flex: 1; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem; text-transform: uppercase;">
                            <button type="submit" class="btn-action" style="background: #059669; color: #ffffff; padding: 0.45rem 0.85rem; font-size: 0.85rem; font-weight: 700; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                                Add
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <table class="struct-table">
                    <thead>
                        <tr>
                            <th>Dept Code</th>
                            <th>Department Name</th>
                            <th>Faculty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($departments)): ?>
                            <tr><td colspan="3" style="color: var(--text-muted); text-align: center;">No departments configured.</td></tr>
                        <?php else: ?>
                            <?php foreach ($departments as $d): ?>
                                <tr>
                                    <td><strong style="font-family: monospace; color: #059669;"><?= e($d['code']); ?></strong></td>
                                    <td><?= e($d['name']); ?></td>
                                    <td style="font-size: 0.75rem; color: var(--text-muted);"><?= e($d['faculty_code']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 3. Programmes Card -->
            <div class="struct-card">
                <div class="struct-header">
                    <span>🎓 Degree Programmes</span>
                    <span style="font-size: 0.8rem; background: #fef3c7; color: #92400e; padding: 0.15rem 0.5rem; border-radius: 9999px;"><?= count($programmes); ?> Active</span>
                </div>

                <?php if (!isSuperAdmin()): ?>
                    <form method="POST" action="academic_structure.php" style="margin-bottom: 1.25rem;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_programme">

                        <div class="form-row">
                            <label for="department_id">Department *</label>
                            <select id="department_id" name="department_id" required>
                                <option value="">-- Select Parent Department --</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['id']; ?>"><?= e($d['name']); ?> (<?= e($d['faculty_code']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <input type="text" name="prog_name" placeholder="Programme Name (e.g. B.Sc. Computer Science)" required style="flex: 2; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem;">
                            <select name="degree_level" required style="flex: 1; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem;">
                                <option value="undergraduate">B.Sc. / B.Tech</option>
                                <option value="postgraduate_pgd">PGD</option>
                                <option value="postgraduate_msc">M.Sc.</option>
                                <option value="postgraduate_phd">Ph.D.</option>
                            </select>
                        </div>

                        <button type="submit" class="btn-action" style="width: 100%; background: #d97706; color: #ffffff; padding: 0.45rem; font-size: 0.85rem; font-weight: 700; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                            Add Degree Programme
                        </button>
                    </form>
                <?php endif; ?>

                <table class="struct-table">
                    <thead>
                        <tr>
                            <th>Programme Name</th>
                            <th>Level</th>
                            <th>Department</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($programmes)): ?>
                            <tr><td colspan="3" style="color: var(--text-muted); text-align: center;">No degree programmes configured.</td></tr>
                        <?php else: ?>
                            <?php foreach ($programmes as $p): ?>
                                <tr>
                                    <td><strong><?= e($p['name']); ?></strong></td>
                                    <td><span style="font-size: 0.7rem; text-transform: uppercase; padding: 0.1rem 0.4rem; background: #f1f5f9; border-radius: 4px;"><?= e(str_replace('_', ' ', $p['degree_level'])); ?></span></td>
                                    <td style="font-size: 0.75rem; color: var(--text-muted);"><?= e($p['department_name']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 4. Academic Sessions Card -->
            <div class="struct-card">
                <div class="struct-header">
                    <span>📅 Academic Sessions</span>
                    <span style="font-size: 0.8rem; background: #e0f2fe; color: #0369a1; padding: 0.15rem 0.5rem; border-radius: 9999px;"><?= count($sessions); ?> Sessions</span>
                </div>

                <?php if (!isSuperAdmin()): ?>
                    <form method="POST" action="academic_structure.php" style="margin-bottom: 1.25rem;">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_session">

                        <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                            <input type="text" name="session_name" placeholder="Session (e.g. 2025/2026)" required style="flex: 2; padding: 0.45rem; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem;">
                            <label style="flex: 1; font-size: 0.8rem; display: flex; align-items: center; gap: 0.25rem; font-weight: 600; color: var(--text-main);">
                                <input type="checkbox" name="is_current" value="1"> Set Current
                            </label>
                            <button type="submit" class="btn-action" style="background: #2563eb; color: #ffffff; padding: 0.45rem 0.85rem; font-size: 0.85rem; font-weight: 700; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                                Add
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <table class="struct-table">
                    <thead>
                        <tr>
                            <th>Academic Session</th>
                            <th>Current Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr><td colspan="2" style="color: var(--text-muted); text-align: center;">No academic sessions configured.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $s): ?>
                                <tr>
                                    <td><strong><?= e($s['session_name']); ?></strong></td>
                                    <td>
                                        <?php if (!empty($s['is_current'])): ?>
                                            <span style="font-size: 0.75rem; font-weight: 700; color: #059669; background: #d1fae5; padding: 0.15rem 0.5rem; border-radius: 9999px;">CURRENT</span>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted);">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

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
