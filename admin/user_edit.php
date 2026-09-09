<?php
/**
 * User Profile & Role Editor (Phase 2 Enhanced)
 * FUD RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/admin_helpers.php';

// Enforce administrator role
requireRole('admin');

$adminUser = currentUser();
$adminId   = (int)$adminUser['id'];

$userId = (int)($_GET['id'] ?? ($_POST['user_id'] ?? 0));
if ($userId <= 0) {
    $_SESSION['admin_error'] = 'Invalid user ID specified.';
    header('Location: users.php');
    exit;
}

// Fetch current user details with supervisor profile & academic programme info
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.phone,
        u.institution,
        u.faculty,
        u.department,
        u.matric_number,
        u.programme_id,
        u.academic_level,
        u.status,
        u.role_id,
        r.name AS role_name,
        sp.staff_id,
        sp.department_id AS supervisor_dept_id,
        sp.max_capacity
    FROM users u
    INNER JOIN roles r ON u.role_id = r.id
    LEFT JOIN supervisor_profiles sp ON u.id = sp.user_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$userRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userRecord) {
    $_SESSION['admin_error'] = 'The requested user account was not found.';
    header('Location: users.php');
    exit;
}

// Fetch available roles (filter out Admin and Super Admin for non-Super-Admins)
if (isSuperAdmin()) {
    $availableRoles = $pdo->query("SELECT id, name, description FROM roles WHERE name != 'super_admin' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $availableRoles = $pdo->query("SELECT id, name, description FROM roles WHERE name NOT IN ('admin', 'super_admin') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$errors = [];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission();
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $errors[] = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        $firstName    = trim($_POST['first_name'] ?? '');
        $lastName     = trim($_POST['last_name'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $institution  = trim($_POST['institution'] ?? '');
        $faculty      = trim($_POST['faculty'] ?? '');
        $department   = trim($_POST['department'] ?? '');
        $matricNumber = trim($_POST['matric_number'] ?? '');
        $programmeId  = (int)($_POST['programme_id'] ?? 0);
        $acadLevel    = trim($_POST['academic_level'] ?? '');
        $newRoleId    = (int)($_POST['role_id'] ?? 0);
        $newStatus    = strtolower(trim($_POST['status'] ?? 'active'));
        
        // Supervisor profile inputs
        $staffId        = trim($_POST['staff_id'] ?? '');
        $supervisorDept = (int)($_POST['supervisor_dept_id'] ?? 0);
        $maxCapacity    = max(1, min(50, (int)($_POST['max_capacity'] ?? 10)));

        // 1. Validate required fields
        if ($firstName === '') {
            $errors[] = 'First Name is required.';
        }
        if ($lastName === '') {
            $errors[] = 'Last Name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid Email address is required.';
        }

        // 2. Validate role & enforce Super Admin boundary for Admin/Super Admin roles
        $roleValid = false;
        $proposedRoleName = '';
        // Fetch proposed role name directly from database to verify
        $rCheck = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $rCheck->execute([$newRoleId]);
        $proposedRoleName = strtolower(trim((string)$rCheck->fetchColumn()));

        if ($proposedRoleName === '') {
            $errors[] = 'Invalid system role selected.';
        } elseif (in_array($proposedRoleName, ['admin', 'super_admin'], true) && !isSuperAdmin()) {
            $errors[] = 'Access Denied: Admin and Super Admin role designation is managed exclusively by Super Admin.';
        }

        // 3. Validate status
        if (!in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
            $errors[] = 'Invalid account status selected.';
        }

        // 4. Email uniqueness check
        if (empty($errors)) {
            $emailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $emailStmt->execute([$email, $userId]);
            if ($emailStmt->fetch()) {
                $errors[] = 'The specified email address is already registered to another user.';
            }
        }

        // 5. Critical Protections: Self-Protection & Last-Admin Protection
        if (empty($errors)) {
            $check = validateAdminModification($pdo, $userId, $adminId, $newRoleId, $newStatus);
            if (!$check['allowed']) {
                $errors[] = $check['error'];
            }
        }

        // 6. Execute update if validations pass
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $oldRoleName = strtolower(trim($userRecord['role_name']));
                $oldStatus   = strtolower(trim($userRecord['status']));

                $upStmt = $pdo->prepare("
                    UPDATE users SET
                        first_name     = :first_name,
                        last_name      = :last_name,
                        email          = :email,
                        phone          = :phone,
                        institution    = :institution,
                        faculty        = :faculty,
                        department     = :department,
                        matric_number  = :matric_number,
                        programme_id   = :programme_id,
                        academic_level = :academic_level,
                        role_id        = :role_id,
                        status         = :status,
                        updated_at     = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");

                $upStmt->execute([
                    ':first_name'     => $firstName,
                    ':last_name'      => $lastName,
                    ':email'          => $email,
                    ':phone'          => $phone ?: null,
                    ':institution'    => $institution ?: null,
                    ':faculty'        => $faculty ?: null,
                    ':department'     => $department ?: null,
                    ':matric_number'  => $matricNumber ?: null,
                    ':programme_id'   => ($programmeId > 0) ? $programmeId : null,
                    ':academic_level' => $acadLevel ?: null,
                    ':role_id'        => $newRoleId,
                    ':status'         => $newStatus,
                    ':id'             => $userId
                ]);

                // Maintain Supervisor Profile if role is supervisor (role_id 2) or staff info provided
                if ($proposedRoleName === 'supervisor' || !empty($staffId)) {
                    $spCheck = $pdo->prepare("SELECT id FROM supervisor_profiles WHERE user_id = ?");
                    $spCheck->execute([$userId]);
                    if ($spCheck->fetch()) {
                        $spUp = $pdo->prepare("
                            UPDATE supervisor_profiles SET
                                staff_id      = :staff_id,
                                department_id = :dept_id,
                                max_capacity  = :cap,
                                updated_at    = CURRENT_TIMESTAMP
                            WHERE user_id = :uid
                        ");
                        $spUp->execute([
                            ':staff_id' => $staffId ?: null,
                            ':dept_id'  => ($supervisorDept > 0) ? $supervisorDept : null,
                            ':cap'      => $maxCapacity,
                            ':uid'      => $userId
                        ]);
                    } else {
                        $spIns = $pdo->prepare("
                            INSERT INTO supervisor_profiles (user_id, staff_id, department_id, max_capacity)
                            VALUES (:uid, :staff_id, :dept_id, :cap)
                        ");
                        $spIns->execute([
                            ':uid'      => $userId,
                            ':staff_id' => $staffId ?: null,
                            ':dept_id'  => ($supervisorDept > 0) ? $supervisorDept : null,
                            ':cap'      => $maxCapacity
                        ]);
                    }
                }

                // Audit log general profile update
                log_audit(
                    $pdo,
                    'user_updated',
                    'users',
                    $userId,
                    "Administrator '{$adminUser['name']}' (#{$adminId}) updated profile for user '{$firstName} {$lastName}' (#{$userId})"
                );

                // Audit role change if changed
                if ((int)$userRecord['role_id'] !== $newRoleId) {
                    log_audit(
                        $pdo,
                        'user_role_changed',
                        'users',
                        $userId,
                        "Administrator '{$adminUser['name']}' changed role for user #{$userId} from '{$oldRoleName}' to '{$proposedRoleName}'"
                    );

                    createNotification(
                        $pdo,
                        $userId,
                        'system_alert',
                        'System Role Updated',
                        "Your system role has been updated to '" . ucfirst($proposedRoleName) . "' by the administrator.",
                        'user',
                        $userId
                    );
                }

                // Audit status change if changed
                if ($oldStatus !== $newStatus) {
                    $auditAction = ($newStatus === 'active') ? 'user_activated' : 'user_deactivated';
                    log_audit(
                        $pdo,
                        $auditAction,
                        'users',
                        $userId,
                        "Administrator '{$adminUser['name']}' changed status for user #{$userId} from '{$oldStatus}' to '{$newStatus}'"
                    );
                }

                $pdo->commit();

                $_SESSION['admin_success'] = "User profile for '{$firstName} {$lastName}' updated successfully.";
                header("Location: user_view.php?id={$userId}");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("User Edit Error: " . $e->getMessage());
                $errors[] = 'A database error occurred while updating the user profile.';
            }
        }
    }
}

$notifUnreadCount = getUnreadNotificationCount($pdo, $adminId);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User: <?= e($userRecord['first_name'] . ' ' . $userRecord['last_name']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 2rem;
            max-width: 850px;
            margin: 1.5rem auto;
            box-shadow: var(--shadow-sm);
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-group label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .form-group input, .form-group select {
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            background: #ffffff;
        }
        .form-section-title {
            grid-column: 1 / -1;
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-top: 1.25rem;
            padding-bottom: 0.35rem;
            border-bottom: 2px solid #f1f5f9;
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

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem;">
            <a href="user_view.php?id=<?= $userId; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to User Profile
            </a>
        </div>

        <!-- Form Card -->
        <div class="form-card">
            <h1 style="font-size: 1.5rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.25rem;">
                ✏️ Edit User Profile & Academic Designation
            </h1>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                Manage account information, student matriculation records, supervisor profiles and system access privileges.
            </p>

            <!-- Errors Alert -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem;">
                    <ul style="margin: 0; padding-left: 1.25rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="user_edit.php?id=<?= $userId; ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                <input type="hidden" name="user_id" value="<?= $userId; ?>">

                <div class="form-grid">
                    <div class="form-section-title">👤 Basic Profile Information</div>

                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" value="<?= e($_POST['first_name'] ?? $userRecord['first_name']); ?>" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" value="<?= e($_POST['last_name'] ?? $userRecord['last_name']); ?>" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? $userRecord['email']); ?>" required maxlength="191">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="text" id="phone" name="phone" value="<?= e($_POST['phone'] ?? $userRecord['phone']); ?>" maxlength="30">
                    </div>

                    <div class="form-section-title">🎓 Student Academic Information</div>

                    <div class="form-group">
                        <label for="matric_number">Matriculation / Student ID</label>
                        <input type="text" id="matric_number" name="matric_number" value="<?= e($_POST['matric_number'] ?? $userRecord['matric_number']); ?>" placeholder="e.g. FUD/2022/CSC/1042">
                    </div>

                    <div class="form-group">
                        <label for="programme_id">Degree Programme</label>
                        <select id="programme_id" name="programme_id">
                            <option value="">-- Select Degree Programme --</option>
                            <?php 
                            $currProgId = (int)($_POST['programme_id'] ?? $userRecord['programme_id']);
                            foreach ($programmes as $p): 
                            ?>
                                <option value="<?= (int)$p['id']; ?>" <?= ($currProgId === (int)$p['id']) ? 'selected' : ''; ?>>
                                    <?= e($p['name']); ?> (<?= e($p['department_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="academic_level">Academic Level</label>
                        <select id="academic_level" name="academic_level">
                            <?php $currLvl = $_POST['academic_level'] ?? $userRecord['academic_level']; ?>
                            <option value="">-- Select Level --</option>
                            <option value="100 Level" <?= ($currLvl === '100 Level') ? 'selected' : ''; ?>>100 Level</option>
                            <option value="200 Level" <?= ($currLvl === '200 Level') ? 'selected' : ''; ?>>200 Level</option>
                            <option value="300 Level" <?= ($currLvl === '300 Level') ? 'selected' : ''; ?>>300 Level</option>
                            <option value="400 Level" <?= ($currLvl === '400 Level') ? 'selected' : ''; ?>>400 Level (Final Year)</option>
                            <option value="500 Level" <?= ($currLvl === '500 Level') ? 'selected' : ''; ?>>500 Level</option>
                            <option value="Postgraduate" <?= ($currLvl === 'Postgraduate') ? 'selected' : ''; ?>>Postgraduate (PGD/M.Sc./Ph.D.)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="department">Department (Text)</label>
                        <input type="text" id="department" name="department" value="<?= e($_POST['department'] ?? $userRecord['department']); ?>" maxlength="255">
                    </div>

                    <div class="form-section-title">👨‍🏫 Supervisor Designation & Profile</div>

                    <div class="form-group">
                        <label for="staff_id">Academic Staff ID</label>
                        <input type="text" id="staff_id" name="staff_id" value="<?= e($_POST['staff_id'] ?? $userRecord['staff_id']); ?>" placeholder="e.g. FUD/STAFF/048">
                    </div>

                    <div class="form-group">
                        <label for="supervisor_dept_id">Supervision Department</label>
                        <select id="supervisor_dept_id" name="supervisor_dept_id">
                            <option value="">-- Select Department --</option>
                            <?php 
                            $currSupDept = (int)($_POST['supervisor_dept_id'] ?? $userRecord['supervisor_dept_id']);
                            foreach ($departments as $d): 
                            ?>
                                <option value="<?= (int)$d['id']; ?>" <?= ($currSupDept === (int)$d['id']) ? 'selected' : ''; ?>>
                                    <?= e($d['name']); ?> (<?= e($d['faculty_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="max_capacity">Max Student Supervision Capacity</label>
                        <input type="number" id="max_capacity" name="max_capacity" min="1" max="50" value="<?= (int)($_POST['max_capacity'] ?? ($userRecord['max_capacity'] ?: 10)); ?>">
                    </div>

                    <div class="form-section-title">🔑 Access Governance & System Roles</div>

                    <div class="form-group">
                        <label for="role_id">System Role *</label>
                        <select id="role_id" name="role_id" required>
                            <?php 
                            $currentRoleId = (int)($_POST['role_id'] ?? $userRecord['role_id']);
                            foreach ($availableRoles as $r): 
                            ?>
                                <option value="<?= (int)$r['id']; ?>" <?= ($currentRoleId === (int)$r['id']) ? 'selected' : ''; ?>>
                                    <?= e(ucfirst($r['name'])); ?> — <?= e($r['description']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Account Status *</label>
                        <select id="status" name="status" required>
                            <?php $currentStatus = strtolower(trim($_POST['status'] ?? $userRecord['status'])); ?>
                            <option value="active" <?= ($currentStatus === 'active') ? 'selected' : ''; ?>>Active (Can sign in & manage data)</option>
                            <option value="inactive" <?= ($currentStatus === 'inactive') ? 'selected' : ''; ?>>Inactive (Login blocked)</option>
                            <option value="suspended" <?= ($currentStatus === 'suspended') ? 'selected' : ''; ?>>Suspended (Account locked)</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border-color);">
                    <a href="user_view.php?id=<?= $userId; ?>" style="padding: 0.6rem 1.25rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; font-weight: 600;">
                        Cancel
                    </a>
                    <button type="submit" class="btn-action" style="background: var(--primary-color); color: #ffffff; padding: 0.6rem 1.5rem; font-weight: 700; border-radius: var(--radius-sm); border: none; cursor: pointer;">
                        Save User Changes
                    </button>
                </div>
            </form>
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
