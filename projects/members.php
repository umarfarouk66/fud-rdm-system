<?php
/**
 * Research Project Team Members Management
 * RDM Information System - Step 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

// Enforce authentication
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$projectId = (int)($_REQUEST['id'] ?? ($_POST['project_id'] ?? 0));

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid research project identifier.';
    header('Location: index.php');
    exit;
}

// Fetch project
try {
    $stmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'The requested research project was not found.';
        header('Location: index.php');
        exit;
    }

    // Determine current user's role in this project
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce team management authorization (Owner, Manager, Admin)
    if (!canManageMembers($projectRole, $systemRole)) {
        $_SESSION['project_error'] = 'Access denied: You do not have permission to manage team members for this project.';
        header("Location: view.php?id={$projectId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Project Members Load Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'Database error loading project details.';
    header('Location: index.php');
    exit;
}

// Handle Form Submissions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Validate CSRF
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['project_error'] = 'Security validation failed (invalid CSRF token).';
        header("Location: members.php?id={$projectId}");
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: ADD MEMBER
    // -------------------------------------------------------------
    if ($action === 'add_member') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $assignedRole = strtolower(trim($_POST['role'] ?? 'viewer'));

        $allowedMemberRoles = ['manager', 'editor', 'uploader', 'viewer'];

        if ($targetUserId <= 0) {
            $_SESSION['project_error'] = 'Please select a valid user to add to the team.';
        } elseif (!in_array($assignedRole, $allowedMemberRoles, true)) {
            $_SESSION['project_error'] = 'Invalid team member role selected. Secondary owners cannot be assigned.';
        } else {
            try {
                // Verify target user exists and is active
                $userStmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = :id AND status = 'active' LIMIT 1");
                $userStmt->execute([':id' => $targetUserId]);
                $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$targetUser) {
                    $_SESSION['project_error'] = 'The selected user does not exist or is inactive.';
                } else {
                    // Check if user is already a member
                    $checkStmt = $pdo->prepare("SELECT id FROM project_members WHERE project_id = :project_id AND user_id = :user_id LIMIT 1");
                    $checkStmt->execute([
                        ':project_id' => $projectId,
                        ':user_id'    => $targetUserId
                    ]);

                    if ($checkStmt->fetch()) {
                        $_SESSION['project_error'] = 'This user is already a member of this research project.';
                    } else {
                        // Insert member
                        $insertStmt = $pdo->prepare("
                            INSERT INTO project_members (
                                project_id,
                                user_id,
                                role,
                                joined_at
                            ) VALUES (
                                :project_id,
                                :user_id,
                                :role,
                                CURRENT_TIMESTAMP
                            )
                        ");
                        $insertStmt->execute([
                            ':project_id' => $projectId,
                            ':user_id'    => $targetUserId,
                            ':role'       => $assignedRole
                        ]);

                        // Record audit log
                        log_audit(
                            $pdo,
                            'project_member_added',
                            'research_project',
                            $projectId,
                            "Added member {$targetUser['first_name']} {$targetUser['last_name']} ({$targetUser['email']}) as {$assignedRole}"
                        );

                        // Notify newly added team member
                        require_once __DIR__ . '/../notifications/notification_helper.php';
                        createNotification(
                            $pdo,
                            $targetUserId,
                            'project_member_added',
                            'Added to Research Project',
                            "You have been added to the project '{$project['title']}' ({$project['project_code']}) as {$assignedRole}.",
                            'project',
                            $projectId
                        );

                        $_SESSION['project_success'] = "Added {$targetUser['first_name']} {$targetUser['last_name']} to the research project team as {$assignedRole}.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Add Member Error: " . $e->getMessage());
                $_SESSION['project_error'] = 'Failed to add member due to a database error.';
            }
        }
        header("Location: members.php?id={$projectId}");
        exit;
    }

    // -------------------------------------------------------------
    // ACTION: REMOVE MEMBER
    // -------------------------------------------------------------
    if ($action === 'remove_member') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $_SESSION['project_error'] = 'Invalid member specified for removal.';
        } elseif ($targetUserId === (int)$project['owner_id']) {
            $_SESSION['project_error'] = 'Cannot remove the primary project owner/lead from the project team.';
        } else {
            try {
                // Fetch target user for audit log
                $userStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1");
                $userStmt->execute([':id' => $targetUserId]);
                $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

                $delStmt = $pdo->prepare("DELETE FROM project_members WHERE project_id = :project_id AND user_id = :user_id");
                $delStmt->execute([
                    ':project_id' => $projectId,
                    ':user_id'    => $targetUserId
                ]);

                if ($delStmt->rowCount() > 0) {
                    $targetName = $targetUser ? ($targetUser['first_name'] . ' ' . $targetUser['last_name']) : "User #{$targetUserId}";
                    log_audit(
                        $pdo,
                        'project_member_removed',
                        'research_project',
                        $projectId,
                        "Removed {$targetName} from project team"
                    );

                    $_SESSION['project_success'] = "Removed {$targetName} from the project team.";
                } else {
                    $_SESSION['project_error'] = 'Member was not found in this project.';
                }
            } catch (PDOException $e) {
                error_log("Remove Member Error: " . $e->getMessage());
                $_SESSION['project_error'] = 'Failed to remove member due to a database error.';
            }
        }
        header("Location: members.php?id={$projectId}");
        exit;
    }
}

// Fetch current team members
$teamMembers = [];
$availableUsers = [];

try {
    // 1. Current team members
    $memberStmt = $pdo->prepare("
        SELECT 
            pm.id AS member_record_id,
            pm.role AS project_role,
            pm.joined_at,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.department,
            u.institution
        FROM project_members pm
        INNER JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = :project_id
        ORDER BY 
            CASE pm.role 
                WHEN 'owner' THEN 1
                WHEN 'manager' THEN 2
                WHEN 'editor' THEN 3
                WHEN 'uploader' THEN 4
                WHEN 'viewer' THEN 5
                ELSE 6 
            END,
            pm.joined_at ASC
    ");
    $memberStmt->execute([':project_id' => $projectId]);
    $teamMembers = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Available institutional users not yet in this project
    $availStmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.department, 
            u.institution,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.status = 'active'
        AND u.id NOT IN (
            SELECT user_id FROM project_members WHERE project_id = :project_id
        )
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $availStmt->execute([':project_id' => $projectId]);
    $availableUsers = $availStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Project Members View Query Error: " . $e->getMessage());
}

// Flash messages
$successMsg = $_SESSION['project_success'] ?? null;
$errorMsg   = $_SESSION['project_error'] ?? null;
unset($_SESSION['project_success'], $_SESSION['project_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Members: <?= e($project['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .members-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .members-grid {
                grid-template-columns: 1fr;
            }
        }
        .content-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            box-shadow: var(--shadow-sm);
        }
        .members-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .members-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
        }
        .members-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .members-table tr:last-child td {
            border-bottom: none;
        }
        .member-badge {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 9999px;
            text-transform: uppercase;
        }
        .badge-owner { background-color: #fee2e2; color: #991b1b; }
        .badge-manager { background-color: #fef3c7; color: #92400e; }
        .badge-editor { background-color: #e0e7ff; color: #3730a3; }
        .badge-uploader { background-color: #d1fae5; color: #065f46; }
        .badge-viewer { background-color: #f1f5f9; color: #475569; }
        .btn-remove {
            color: #dc2626;
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-remove:hover {
            background-color: #dc2626;
            color: #ffffff;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.4rem;
        }
        .form-control {
            width: 100%;
            padding: 0.65rem 0.85rem;
            font-size: 0.95rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-sizing: border-box;
            background: #ffffff;
            color: var(--text-main);
        }
        .btn-add {
            width: 100%;
            background-color: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-add:hover {
            background-color: #1e40af;
        }
        .role-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.75rem;
            line-height: 1.4;
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <header class="dash-navbar">
        <a href="index.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                </div>
                <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Flash Notifications -->
        <?php if (!empty($successMsg)): ?>
            <div class="dash-alert dash-alert-success">
                <span><?= e($successMsg); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div class="dash-alert dash-alert-danger">
                <span><?= e($errorMsg); ?></span>
            </div>
        <?php endif; ?>

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="view.php?id=<?= (int)$project['id']; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Project View
            </a>
            <span style="font-family: monospace; font-weight: 700; color: var(--text-muted);">
                <?= e($project['project_code']); ?>
            </span>
        </div>

        <div style="margin-bottom: 2rem;">
            <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.35rem;">
                Manage Project Team Members
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Assign research collaborators and configure granular role-based permissions for <strong><?= e($project['title']); ?></strong>.
            </p>
        </div>

        <div class="members-grid">

            <!-- Left Column: Current Members Table -->
            <div class="content-card">
                <h2 class="section-title" style="margin-bottom: 1rem;">
                    Current Project Team (<?= count($teamMembers); ?>)
                </h2>

                <table class="members-table">
                    <thead>
                        <tr>
                            <th>Researcher</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamMembers as $m): 
                            $isLeadOwner = ((int)$m['user_id'] === (int)$project['owner_id']);
                        ?>
                            <tr>
                                <td>
                                    <strong><?= e($m['first_name'] . ' ' . $m['last_name']); ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($m['email']); ?></div>
                                </td>
                                <td><?= e($m['department'] ?: $m['institution']); ?></td>
                                <td>
                                    <span class="member-badge badge-<?= e($m['project_role']); ?>">
                                        <?= e($m['project_role']); ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($m['joined_at'])); ?></td>
                                <td>
                                    <?php if ($isLeadOwner): ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Owner</span>
                                    <?php else: ?>
                                        <form action="members.php" method="POST" onsubmit="return confirm('Are you sure you want to remove this member from the project team?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                                            <input type="hidden" name="user_id" value="<?= (int)$m['user_id']; ?>">
                                            <button type="submit" class="btn-remove">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Right Column: Searchable Add Team Member Form -->
            <div class="content-card">
                <h2 class="section-title" style="margin-bottom: 1rem;">➕ Add Team Member</h2>

                <form action="members.php" method="POST" id="add_member_form">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">
                    <input type="hidden" name="user_id" id="selected_user_id" value="" required>

                    <!-- Search Input Field -->
                    <div class="form-group" id="search_input_group">
                        <label for="member_search_input" class="form-label">Search Institutional User *</label>
                        <div style="position: relative;">
                            <input type="text" id="member_search_input" class="form-control" placeholder="Search by name, email or user ID..." autocomplete="off">
                            <span id="search_spinner" style="display:none; position:absolute; right:12px; top:10px; font-size:0.85rem;">⏳</span>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem;">
                            Type a researcher's name, email address or ID number to search available members.
                        </div>
                    </div>

                    <!-- Dynamic Search Results Box -->
                    <div id="search_results_container" style="display:none; margin-bottom: 1.25rem; max-height: 250px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: #ffffff; box-shadow: var(--shadow-sm);">
                        <!-- Results dynamically populated via JS -->
                    </div>

                    <!-- Selected Member Card -->
                    <div id="selected_member_card" style="display:none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.25rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                            <div>
                                <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: #166534; letter-spacing: 0.05em; margin-bottom: 0.2rem;">Selected Team Member</div>
                                <strong id="selected_user_name" style="font-size: 0.95rem; color: #065f46; display: block;"></strong>
                                <div id="selected_user_detail" style="font-size: 0.8rem; color: #047857; margin-top: 0.15rem;"></div>
                            </div>
                            <button type="button" class="btn-remove" style="background: #ffffff; color: #dc2626; padding: 0.3rem 0.65rem;" onclick="clearSelectedUser()">Change</button>
                        </div>
                    </div>

                    <!-- Fallback Dropdown (visible if JS disabled or browse all) -->
                    <div class="form-group" id="fallback_dropdown_group">
                        <label for="fallback_user_id" class="form-label">Or Select from List</label>
                        <select id="fallback_user_id" class="form-control" onchange="if(this.value){ selectUserFromDropdown(this); }">
                            <option value="">-- Choose from available users (<?= count($availableUsers); ?>) --</option>
                            <?php foreach ($availableUsers as $au): ?>
                                <option value="<?= (int)$au['id']; ?>" data-name="<?= e($au['first_name'] . ' ' . $au['last_name']); ?>" data-email="<?= e($au['email']); ?>" data-role="<?= e($au['role_name']); ?>" data-dept="<?= e($au['department'] ?: $au['institution']); ?>">
                                    <?= e($au['first_name'] . ' ' . $au['last_name']); ?> (<?= e($au['email']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Role Selection Dropdown -->
                    <div class="form-group" id="role_selection_group" style="display:none;">
                        <label for="role" class="form-label">Project Role *</label>
                        <select id="role" name="role" class="form-control" required>
                            <option value="editor">Editor / Contributor (Can edit metadata)</option>
                            <option value="manager">Manager (Can edit project &amp; manage team)</option>
                            <option value="uploader">Data Uploader (Can view and deposit files)</option>
                            <option value="viewer">Viewer / Observer (Read-only access)</option>
                        </select>
                    </div>

                    <div class="role-desc" id="role_desc_box" style="display:none;">
                        <strong>Role Permissions:</strong>
                        <ul style="margin-left: 1.2rem; margin-top: 0.35rem;">
                            <li><strong>Manager:</strong> Edit project &amp; manage team</li>
                            <li><strong>Editor:</strong> Edit project details &amp; outputs</li>
                            <li><strong>Uploader / Viewer:</strong> View project data</li>
                        </ul>
                    </div>

                    <div class="form-group" id="submit_group" style="display:none; margin-top: 1.5rem;">
                        <button type="submit" class="btn-add">Add to Project Team</button>
                    </div>
                </form>
            </div>

        </div>

    </main>

    <script>
        const projectId = <?= (int)$project['id']; ?>;
        const searchInput = document.getElementById('member_search_input');
        const spinner = document.getElementById('search_spinner');
        const resultsBox = document.getElementById('search_results_container');
        const selectedCard = document.getElementById('selected_member_card');
        const selectedUserIdInput = document.getElementById('selected_user_id');
        const selectedUserName = document.getElementById('selected_user_name');
        const selectedUserDetail = document.getElementById('selected_user_detail');
        const fallbackGroup = document.getElementById('fallback_dropdown_group');
        const roleGroup = document.getElementById('role_selection_group');
        const roleDesc = document.getElementById('role_desc_box');
        const submitGroup = document.getElementById('submit_group');
        let debounceTimer = null;

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const q = this.value.trim();
                if (q.length === 0) {
                    resultsBox.style.display = 'none';
                    resultsBox.innerHTML = '';
                    return;
                }
                spinner.style.display = 'inline';
                debounceTimer = setTimeout(() => {
                    fetchUsers(q);
                }, 250);
            });
        }

        function fetchUsers(query) {
            fetch(`search_users.php?project_id=${projectId}&q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    spinner.style.display = 'none';
                    if (data.error) {
                        resultsBox.innerHTML = `<div style="padding:0.75rem; color:#dc2626; font-size:0.85rem;">${escapeHtml(data.error)}</div>`;
                        resultsBox.style.display = 'block';
                        return;
                    }
                    if (!Array.isArray(data) || data.length === 0) {
                        resultsBox.innerHTML = `<div style="padding:1rem; text-align:center; color:var(--text-muted); font-size:0.875rem;">No matching user found.</div>`;
                        resultsBox.style.display = 'block';
                        return;
                    }
                    
                    let html = '<div style="padding:0.5rem 0.75rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Select the correct team member</div>';
                    data.forEach(user => {
                        const name = escapeHtml(`${user.first_name} ${user.last_name}`);
                        const email = escapeHtml(user.email);
                        const role = escapeHtml(user.role_name || 'User');
                        const dept = escapeHtml(user.department || user.institution || '');
                        
                        html += `
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem; border-bottom:1px solid #f1f5f9; cursor:pointer;" onclick="selectUser(${user.id}, '${name}', '${email}', '${role}', '${dept}')">
                                <div>
                                    <strong style="color:var(--text-main); font-size:0.9rem;">${name}</strong>
                                    <div style="font-size:0.8rem; color:var(--text-muted);">${email} &bull; ${role} ${dept ? '(' + dept + ')' : ''}</div>
                                </div>
                                <button type="button" class="btn-remove" style="background:var(--primary-color); color:#ffffff; border:none; padding:0.25rem 0.65rem;" onclick="selectUser(${user.id}, '${name}', '${email}', '${role}', '${dept}')">+ Add</button>
                            </div>
                        `;
                    });
                    resultsBox.innerHTML = html;
                    resultsBox.style.display = 'block';
                })
                .catch(err => {
                    spinner.style.display = 'none';
                    console.error('Search error:', err);
                });
        }

        function selectUser(id, name, email, role, dept) {
            selectedUserIdInput.value = id;
            selectedUserName.textContent = name;
            selectedUserDetail.textContent = `${email} • ${role} ${dept ? '(' + dept + ')' : ''}`;
            
            resultsBox.style.display = 'none';
            if (searchInput) searchInput.value = name;
            fallbackGroup.style.display = 'none';
            selectedCard.style.display = 'block';
            roleGroup.style.display = 'block';
            roleDesc.style.display = 'block';
            submitGroup.style.display = 'block';
        }

        function selectUserFromDropdown(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            if (!opt.value) return;
            const id = opt.value;
            const name = opt.getAttribute('data-name');
            const email = opt.getAttribute('data-email');
            const role = opt.getAttribute('data-role');
            const dept = opt.getAttribute('data-dept');
            selectUser(id, name, email, role, dept);
        }

        function clearSelectedUser() {
            selectedUserIdInput.value = '';
            selectedCard.style.display = 'none';
            roleGroup.style.display = 'none';
            roleDesc.style.display = 'none';
            submitGroup.style.display = 'none';
            fallbackGroup.style.display = 'block';
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            document.getElementById('fallback_user_id').value = '';
        }

        function escapeHtml(str) {
            return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    </script>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
