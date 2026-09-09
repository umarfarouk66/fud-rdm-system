<?php
/**
 * Edit Data Management Plan (DMP)
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
            p.project_code,
            p.title AS project_title,
            p.owner_id AS project_owner_id
        FROM data_management_plans d
        INNER JOIN research_projects p ON d.project_id = p.id
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

    // Enforce edit authorization (Owner, Manager, Admin)
    if (!canManageMembers($projectRole, $systemRole)) {
        $_SESSION['dmp_error'] = 'Access denied: You do not have permission to edit this Data Management Plan.';
        header("Location: view.php?id={$dmpId}");
        exit;
    }

    // Enforce lifecycle status lock: Only draft or rejected DMPs can be edited
    if (!canEditDmp($projectRole, $systemRole, $dmp['status'])) {
        $_SESSION['dmp_error'] = "This DMP is currently in '{$dmp['status']}' status and cannot be edited.";
        header("Location: view.php?id={$dmpId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("DMP Edit Query Error: " . $e->getMessage());
    $_SESSION['dmp_error'] = 'A system error occurred while retrieving the DMP.';
    header('Location: index.php');
    exit;
}

// Retrieve flash errors & old input
$errors = $_SESSION['edit_dmp_errors'] ?? [];
$old    = $_SESSION['old_edit_dmp_data'] ?? $dmp;
unset($_SESSION['edit_dmp_errors'], $_SESSION['old_edit_dmp_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit DMP: <?= e($dmp['project_title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-sm);
            max-width: 950px;
            margin: 0 auto 3rem auto;
        }
        .form-section-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.75rem;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .section-number {
            background: var(--primary-color);
            color: #ffffff;
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: var(--radius-sm);
        }
        .section-title-text {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .form-group {
            margin-bottom: 1rem;
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
        textarea.form-control {
            min-height: 90px;
            font-family: inherit;
            resize: vertical;
            line-height: 1.5;
        }
        .form-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
            line-height: 1.4;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 1rem;
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .btn-draft {
            background: #ffffff;
            color: var(--text-main);
            border: 1px solid var(--border-color);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.15s ease;
        }
        .btn-draft:hover {
            background-color: #f1f5f9;
            border-color: #94a3b8;
        }
        .btn-submit {
            background-color: #059669;
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
            background-color: #047857;
        }
        .btn-cancel {
            background: transparent;
            color: var(--text-muted);
            font-weight: 600;
            padding: 0.75rem 1rem;
            text-decoration: none;
        }
        .btn-cancel:hover {
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
            <a href="view.php?id=<?= (int)$dmp['id']; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to DMP View
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); font-size: 0.9rem;">
                    <?= e($dmp['project_code']); ?>
                </span>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem; margin-bottom: 0.4rem;">
                    Edit Data Management Plan
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Updating DMP for <strong><?= e($dmp['project_title']); ?></strong>
                </p>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 2rem;">
                    <strong>Please address the following requirements:</strong>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- DMP Edit Form -->
            <form action="update.php" method="POST" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="dmp_id" value="<?= (int)$dmp['id']; ?>">

                <!-- Section A: Data Description -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section A</span>
                        <span class="section-title-text">Data Description</span>
                    </div>
                    <div class="form-group">
                        <label for="data_description" class="form-label">Data Description & Purpose <span class="required">*</span></label>
                        <textarea id="data_description" name="data_description" class="form-control"><?= e($old['data_description'] ?? ''); ?></textarea>
                        <p class="form-hint">Summarize research data sources, experimental instruments, simulations or third-party datasets.</p>
                    </div>
                </div>

                <!-- Section B: Data Type -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section B</span>
                        <span class="section-title-text">Data Type & Formats</span>
                    </div>
                    <div class="form-group">
                        <label for="data_type" class="form-label">Primary Data Nature & Format <span class="required">*</span></label>
                        <input type="text" id="data_type" name="data_type" class="form-control" value="<?= e($old['data_type'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Section C: Expected Volume -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section C</span>
                        <span class="section-title-text">Expected Data Volume</span>
                    </div>
                    <div class="form-group">
                        <label for="expected_volume" class="form-label">Estimated Storage Capacity <span class="required">*</span></label>
                        <input type="text" id="expected_volume" name="expected_volume" class="form-control" value="<?= e($old['expected_volume'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Section D: Data Organization -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section D</span>
                        <span class="section-title-text">Data Organization & Documentation</span>
                    </div>
                    <div class="form-group">
                        <label for="data_organisation" class="form-label">File Naming, Directory Structure & Metadata <span class="required">*</span></label>
                        <textarea id="data_organisation" name="data_organisation" class="form-control"><?= e($old['data_organisation'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section E: Storage Location -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section E</span>
                        <span class="section-title-text">Storage Location</span>
                    </div>
                    <div class="form-group">
                        <label for="storage_location" class="form-label">Active Research Storage Environments <span class="required">*</span></label>
                        <input type="text" id="storage_location" name="storage_location" class="form-control" value="<?= e($old['storage_location'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Section F: Protection Measures -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section F</span>
                        <span class="section-title-text">Protection & Security Measures</span>
                    </div>
                    <div class="form-group">
                        <label for="protection_measures" class="form-label">Security & Backup Safeguards <span class="required">*</span></label>
                        <textarea id="protection_measures" name="protection_measures" class="form-control"><?= e($old['protection_measures'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section G: Access Control -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section G</span>
                        <span class="section-title-text">Access Control</span>
                    </div>
                    <div class="form-group">
                        <label for="access_control" class="form-label">Authorization & Permissions Management <span class="required">*</span></label>
                        <textarea id="access_control" name="access_control" class="form-control"><?= e($old['access_control'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section H: Sensitive Data Handling -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section H</span>
                        <span class="section-title-text">Sensitive Data Handling</span>
                    </div>
                    <div class="form-group">
                        <label for="sensitive_data_handling" class="form-label">Confidential, Personal & Ethical Data Protocols <span class="required">*</span></label>
                        <textarea id="sensitive_data_handling" name="sensitive_data_handling" class="form-control"><?= e($old['sensitive_data_handling'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section I: Data Sharing -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section I</span>
                        <span class="section-title-text">Data Sharing Plan</span>
                    </div>
                    <div class="form-group">
                        <label for="sharing_plan" class="form-label">Sharing Protocols, Embargoes & Open Access <span class="required">*</span></label>
                        <textarea id="sharing_plan" name="sharing_plan" class="form-control"><?= e($old['sharing_plan'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section J: Retention Period -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section J</span>
                        <span class="section-title-text">Retention Period</span>
                    </div>
                    <div class="form-group">
                        <label for="retention_period" class="form-label">Retention Schedule <span class="required">*</span></label>
                        <input type="text" id="retention_period" name="retention_period" class="form-control" value="<?= e($old['retention_period'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Section K: Digital Preservation -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section K</span>
                        <span class="section-title-text">Digital Preservation Plan</span>
                    </div>
                    <div class="form-group">
                        <label for="preservation_plan" class="form-label">Long-Term Archiving & Standard Formats <span class="required">*</span></label>
                        <textarea id="preservation_plan" name="preservation_plan" class="form-control"><?= e($old['preservation_plan'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Section L: Disposal -->
                <div class="form-section-box">
                    <div class="section-header">
                        <span class="section-number">Section L</span>
                        <span class="section-title-text">Secure Disposal Plan</span>
                    </div>
                    <div class="form-group">
                        <label for="disposal_plan" class="form-label">Data Destruction & Sanitization Procedures <span class="required">*</span></label>
                        <textarea id="disposal_plan" name="disposal_plan" class="form-control"><?= e($old['disposal_plan'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <a href="view.php?id=<?= (int)$dmp['id']; ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" name="submit_action" value="draft" class="btn-draft">
                        💾 Save Changes (Draft)
                    </button>
                    <button type="submit" name="submit_action" value="submit" class="btn-submit">
                        🚀 Submit for Review &rarr;
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
