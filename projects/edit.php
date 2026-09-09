<?php
/**
 * Edit Research Project View
 * RDM Information System - Step 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$projectId = (int)($_GET['id'] ?? 0);

if ($projectId <= 0) {
    $_SESSION['project_error'] = 'Invalid research project identifier.';
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM research_projects WHERE id = :project_id LIMIT 1");
    $stmt->execute([':project_id' => $projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        $_SESSION['project_error'] = 'The requested research project was not found.';
        header('Location: index.php');
        exit;
    }

    // Determine role and check edit permission
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    if (!canEditProject($projectRole, $systemRole)) {
        $_SESSION['project_error'] = 'Access denied: You do not have permission to edit this research project.';
        header("Location: view.php?id={$projectId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Project Edit Query Error: " . $e->getMessage());
    $_SESSION['project_error'] = 'Database error loading project details.';
    header('Location: index.php');
    exit;
}

// Retrieve flash errors & old inputs
$errors = $_SESSION['edit_project_errors'] ?? [];
$old    = $_SESSION['old_edit_project_data'] ?? $project;
unset($_SESSION['edit_project_errors'], $_SESSION['old_edit_project_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project: <?= e($project['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-sm);
            max-width: 900px;
            margin: 0 auto 3rem auto;
        }
        .form-section-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 0.5rem;
            margin: 2rem 0 1.25rem 0;
        }
        .form-section-title:first-of-type {
            margin-top: 0;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 700px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
        .form-label .required {
            color: #dc2626;
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
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .form-control-readonly {
            background-color: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }
        textarea.form-control {
            min-height: 100px;
            font-family: inherit;
            resize: vertical;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .btn-submit {
            background-color: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            padding: 0.75rem 1.75rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
            transition: background-color 0.15s ease;
        }
        .btn-submit:hover {
            background-color: #1e40af;
        }
        .btn-cancel {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-cancel:hover {
            background: #f8fafc;
            color: var(--text-main);
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

        <!-- Top Breadcrumb -->
        <div style="margin-bottom: 1.5rem;">
            <a href="view.php?id=<?= (int)$project['id']; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Project View
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.4rem;">
                    Edit Research Project
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Update project details, research objectives and operational status.
                </p>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 2rem;">
                    <strong>Please correct the following errors:</strong>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="update.php" method="POST" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="project_id" value="<?= (int)$project['id']; ?>">

                <!-- Read-Only System Identifiers -->
                <div class="form-row" style="margin-bottom: 1.5rem;">
                    <div class="form-group">
                        <label class="form-label">Project Code (Immutable)</label>
                        <input type="text" class="form-control form-control-readonly" value="<?= e($project['project_code']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Project Lifecycle Status <span class="required">*</span></label>
                        <select id="status" name="status" class="form-control" required>
                            <option value="planning" <?= ($old['status'] === 'planning') ? 'selected' : ''; ?>>Planning</option>
                            <option value="active" <?= ($old['status'] === 'active') ? 'selected' : ''; ?>>Active / In Progress</option>
                            <option value="completed" <?= ($old['status'] === 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="archived" <?= ($old['status'] === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            <option value="cancelled" <?= ($old['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                </div>

                <!-- Section 1: Overview -->
                <h2 class="form-section-title">1. Project Overview</h2>

                <div class="form-group">
                    <label for="title" class="form-label">Project Title <span class="required">*</span></label>
                    <input 
                        type="text" 
                        id="title" 
                        name="title" 
                        class="form-control" 
                        value="<?= e($old['title'] ?? ''); ?>" 
                        required
                        maxlength="255"
                    >
                </div>

                <div class="form-group">
                    <label for="description" class="form-label">Project Description</label>
                    <textarea 
                        id="description" 
                        name="description" 
                        class="form-control"
                    ><?= e($old['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="research_area" class="form-label">Research Area / Domain <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="research_area" 
                            name="research_area" 
                            class="form-control" 
                            value="<?= e($old['research_area'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="faculty" class="form-label">Faculty / School <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="faculty" 
                            name="faculty" 
                            class="form-control" 
                            value="<?= e($old['faculty'] ?? ''); ?>" 
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="department" class="form-label">Academic Department <span class="required">*</span></label>
                    <input 
                        type="text" 
                        id="department" 
                        name="department" 
                        class="form-control" 
                        value="<?= e($old['department'] ?? ''); ?>" 
                        required
                    >
                </div>

                <!-- Section 2: Research Details -->
                <h2 class="form-section-title">2. Research Details & Methodology</h2>

                <div class="form-group">
                    <label for="objectives" class="form-label">Research Objectives <span class="required">*</span></label>
                    <textarea 
                        id="objectives" 
                        name="objectives" 
                        class="form-control" 
                        required
                    ><?= e($old['objectives'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="methodology" class="form-label">Research Methodology</label>
                    <textarea 
                        id="methodology" 
                        name="methodology" 
                        class="form-control"
                    ><?= e($old['methodology'] ?? ''); ?></textarea>
                </div>

                <!-- Section 3: Data Attributes -->
                <h2 class="form-section-title">3. Data Characteristics</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="data_type" class="form-label">Primary Data Type <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="data_type" 
                            name="data_type" 
                            class="form-control" 
                            value="<?= e($old['data_type'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="data_collection_location" class="form-label">Data Collection Location <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="data_collection_location" 
                            name="data_collection_location" 
                            class="form-control" 
                            value="<?= e($old['data_collection_location'] ?? ''); ?>" 
                            required
                        >
                    </div>
                </div>

                <!-- Section 4: Administration & Dates -->
                <h2 class="form-section-title">4. Governance, Funding & Schedule</h2>

                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date" class="form-label">Start Date <span class="required">*</span></label>
                        <input 
                            type="date" 
                            id="start_date" 
                            name="start_date" 
                            class="form-control" 
                            value="<?= e($old['start_date'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="completion_date" class="form-label">Anticipated Completion Date</label>
                        <input 
                            type="date" 
                            id="completion_date" 
                            name="completion_date" 
                            class="form-control" 
                            value="<?= e($old['completion_date'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="funding_information" class="form-label">Funding Information / Grant ID</label>
                    <input 
                        type="text" 
                        id="funding_information" 
                        name="funding_information" 
                        class="form-control" 
                        value="<?= e($old['funding_information'] ?? ''); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="ethical_approval_information" class="form-label">Ethical Approval Information</label>
                    <input 
                        type="text" 
                        id="ethical_approval_information" 
                        name="ethical_approval_information" 
                        class="form-control" 
                        value="<?= e($old['ethical_approval_information'] ?? ''); ?>"
                    >
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <a href="view.php?id=<?= (int)$project['id']; ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">Save Changes &rarr;</button>
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
