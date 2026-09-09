<?php
/**
 * View Data Management Plan (DMP)
 * RDM Information System - Step 6
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dmp_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$dmpId = (int)($_GET['id'] ?? 0);

if ($dmpId <= 0) {
    $_SESSION['dmp_error'] = 'Invalid Data Management Plan identifier.';
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            p.research_area,
            p.department AS project_department,
            p.faculty AS project_faculty,
            p.owner_id AS project_owner_id,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email
        FROM data_management_plans d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON p.owner_id = u.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $dmpId]);
    $dmp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dmp) {
        $_SESSION['dmp_error'] = 'The requested Data Management Plan was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dmp['project_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce view authorization
    if (!canViewProject($projectRole, $systemRole)) {
        $_SESSION['dmp_error'] = 'Access denied: You do not have permission to view this Data Management Plan.';
        header('Location: index.php');
        exit;
    }

    $canEdit   = canEditDmp($projectRole, $systemRole, $dmp['status']);
    $canSubmit = canSubmitDmp($projectRole, $systemRole, $dmp['status']);

} catch (PDOException $e) {
    error_log("DMP View Query Error: " . $e->getMessage());
    $_SESSION['dmp_error'] = 'A system error occurred while retrieving the DMP.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['dmp_success'] ?? null;
$errorMsg   = $_SESSION['dmp_error'] ?? null;
unset($_SESSION['dmp_success'], $_SESSION['dmp_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMP: <?= e($dmp['project_title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .view-header {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .project-code-tag {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
            display: inline-block;
        }
        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-header {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            transition: all 0.15s ease;
        }
        .btn-header:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background-color: var(--primary-light);
        }
        .btn-header-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-header-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-header-success {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
            cursor: pointer;
        }
        .btn-header-success:hover {
            background-color: #047857;
            color: #ffffff;
        }
        .section-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .section-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary-color);
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-badge {
            background: var(--primary-light);
            color: var(--accent-color);
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
        }
        .section-content {
            font-size: 0.95rem;
            line-height: 1.65;
            color: var(--text-main);
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem 1.25rem;
            white-space: pre-wrap;
            margin-top: 0.35rem;
        }
        .empty-field {
            color: #94a3b8;
            font-style: italic;
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
        <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to DMP Directory
            </a>
            <a href="../projects/view.php?id=<?= $projectId; ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;">
                View Project Workspace &rarr;
            </a>
        </div>

        <!-- Header Overview Card -->
        <div class="view-header">
            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($dmp['project_code']); ?></span>
                    <span style="margin-left: 0.5rem;">
                        <?= getDmpStatusBadge($dmp['status']); ?>
                    </span>
                </div>
                <div class="header-actions">
                    <?php if ($canEdit): ?>
                        <a href="edit.php?id=<?= (int)$dmp['id']; ?>" class="btn-header btn-header-primary">
                            ✏️ Edit DMP
                        </a>
                    <?php endif; ?>

                    <?php if ($canSubmit): ?>
                        <form action="submit.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to submit this Data Management Plan for formal review?');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="dmp_id" value="<?= (int)$dmp['id']; ?>">
                            <button type="submit" class="btn-header btn-header-success">
                                🚀 Submit for Review
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <h1 style="font-size: 1.85rem; color: var(--primary-color); font-weight: 700; line-height: 1.25; margin-bottom: 0.75rem;">
                Data Management Plan: <?= e($dmp['project_title']); ?>
            </h1>

            <div style="font-size: 0.9rem; color: var(--text-muted); display: flex; gap: 1.5rem; flex-wrap: wrap;">
                <div><strong>Lead Investigator:</strong> <?= e($dmp['owner_first_name'] . ' ' . $dmp['owner_last_name']); ?></div>
                <div><strong>Department:</strong> <?= e($dmp['project_department']); ?></div>
                <div><strong>Last Updated:</strong> <?= date('M d, Y H:i', strtotime($dmp['updated_at'])); ?></div>
            </div>
        </div>

        <!-- Sections A through L -->

        <!-- Section A: Data Description -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section A</span>
                <span>Data Description & Purpose</span>
            </div>
            <div class="section-content"><?= !empty($dmp['data_description']) ? e($dmp['data_description']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section B: Data Type -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section B</span>
                <span>Data Type & Formats</span>
            </div>
            <div class="section-content"><?= !empty($dmp['data_type']) ? e($dmp['data_type']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section C: Expected Volume -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section C</span>
                <span>Expected Data Volume</span>
            </div>
            <div class="section-content"><?= !empty($dmp['expected_volume']) ? e($dmp['expected_volume']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section D: Data Organization -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section D</span>
                <span>Data Organization & Documentation</span>
            </div>
            <div class="section-content"><?= !empty($dmp['data_organisation']) ? e($dmp['data_organisation']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section E: Storage Location -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section E</span>
                <span>Storage Location & Active Environments</span>
            </div>
            <div class="section-content"><?= !empty($dmp['storage_location']) ? e($dmp['storage_location']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section F: Protection Measures -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section F</span>
                <span>Protection & Security Safeguards</span>
            </div>
            <div class="section-content"><?= !empty($dmp['protection_measures']) ? e($dmp['protection_measures']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section G: Access Control -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section G</span>
                <span>Access Control & Permissions</span>
            </div>
            <div class="section-content"><?= !empty($dmp['access_control']) ? e($dmp['access_control']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section H: Sensitive Data Handling -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section H</span>
                <span>Sensitive Data Handling & Ethics</span>
            </div>
            <div class="section-content"><?= !empty($dmp['sensitive_data_handling']) ? e($dmp['sensitive_data_handling']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section I: Data Sharing -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section I</span>
                <span>Data Sharing & Open Access Plan</span>
            </div>
            <div class="section-content"><?= !empty($dmp['sharing_plan']) ? e($dmp['sharing_plan']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section J: Retention Period -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section J</span>
                <span>Retention Period & Schedule</span>
            </div>
            <div class="section-content"><?= !empty($dmp['retention_period']) ? e($dmp['retention_period']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section K: Digital Preservation -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section K</span>
                <span>Digital Preservation Plan</span>
            </div>
            <div class="section-content"><?= !empty($dmp['preservation_plan']) ? e($dmp['preservation_plan']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
        </div>

        <!-- Section L: Disposal -->
        <div class="section-box">
            <div class="section-label">
                <span class="section-badge">Section L</span>
                <span>Secure Disposal Plan</span>
            </div>
            <div class="section-content"><?= !empty($dmp['disposal_plan']) ? e($dmp['disposal_plan']) : '<span class="empty-field">[Incomplete — Not yet documented]</span>'; ?></div>
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
