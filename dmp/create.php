<?php
/**
 * Create Data Management Plan (DMP) View
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

// Requested project ID from query (if coming from a project view page)
$selectedProjectId = (int)($_GET['project_id'] ?? 0);

// If project ID provided, check if DMP already exists for this project
if ($selectedProjectId > 0) {
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM data_management_plans WHERE project_id = :project_id LIMIT 1");
        $checkStmt->execute([':project_id' => $selectedProjectId]);
        $existingDmp = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingDmp) {
            $_SESSION['dmp_error'] = 'This project already has an existing Data Management Plan.';
            header("Location: view.php?id={$existingDmp['id']}");
            exit;
        }
    } catch (PDOException $e) {
        error_log("DMP Project Check Error: " . $e->getMessage());
    }
}

// Fetch eligible projects where user is owner or manager and project has no DMP yet
$eligibleProjects = [];
try {
    if ($systemRole === 'admin') {
        $stmt = $pdo->query("
            SELECT p.id, p.project_code, p.title 
            FROM research_projects p 
            WHERE p.id NOT IN (SELECT project_id FROM data_management_plans)
            ORDER BY p.title ASC
        ");
        $eligibleProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id, p.project_code, p.title 
            FROM research_projects p 
            LEFT JOIN project_members pm ON p.id = pm.project_id 
            WHERE (p.owner_id = :user_id OR (pm.user_id = :user_id AND pm.role IN ('owner', 'manager')))
            AND p.id NOT IN (SELECT project_id FROM data_management_plans)
            ORDER BY p.title ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $eligibleProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Eligible Projects Fetch Error: " . $e->getMessage());
}

// Retrieve flash errors & old input
$errors = $_SESSION['create_dmp_errors'] ?? [];
$old    = $_SESSION['old_dmp_data'] ?? [];
unset($_SESSION['create_dmp_errors'], $_SESSION['old_dmp_data']);

$activeProjectId = !empty($old['project_id']) ? (int)$old['project_id'] : $selectedProjectId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Data Management Plan (DMP) — FUD RDM System</title>
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
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to DMP Directory
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.4rem;">
                    Author Data Management Plan (DMP)
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Define the full lifecycle protocols for collecting, storing, protecting, sharing and preserving research data.
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

            <?php if (empty($eligibleProjects)): ?>
                <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1.5rem; border-radius: var(--radius-md); text-align: center;">
                    <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;">No Eligible Projects Available</h3>
                    <p style="font-size: 0.9rem; margin-bottom: 1rem;">
                        You must be the owner or manager of an active research project that does not already have a DMP.
                    </p>
                    <a href="../projects/create.php" class="btn-action btn-action-primary" style="padding: 0.6rem 1.2rem;">
                        ➕ Create a New Research Project
                    </a>
                </div>
            <?php else: ?>

                <!-- DMP Form -->
                <form action="store.php" method="POST" autocomplete="off">
                    <?= csrf_field(); ?>

                    <!-- Project Selection -->
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem;">
                        <label for="project_id" class="form-label" style="color: var(--primary-color); font-size: 0.95rem;">
                            Select Associated Research Project <span class="required">*</span>
                        </label>
                        <select id="project_id" name="project_id" class="form-control" required>
                            <option value="">-- Choose Research Project --</option>
                            <?php foreach ($eligibleProjects as $ep): ?>
                                <option value="<?= (int)$ep['id']; ?>" <?= ($activeProjectId === (int)$ep['id']) ? 'selected' : ''; ?>>
                                    <?= e($ep['project_code']); ?> — <?= e($ep['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Each research project has exactly one unified Data Management Plan.</p>
                    </div>

                    <!-- Section A: Data Description -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-number">Section A</span>
                            <span class="section-title-text">Data Description</span>
                        </div>
                        <div class="form-group">
                            <label for="data_description" class="form-label">Data Description & Purpose <span class="required">*</span></label>
                            <textarea id="data_description" name="data_description" class="form-control" placeholder="What data will be collected, generated or reused? What is the scientific or operational purpose of the data?"><?= e($old['data_description'] ?? ''); ?></textarea>
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
                            <input type="text" id="data_type" name="data_type" class="form-control" placeholder="e.g. Quantitative CSV/Tabular, Geospatial NetCDF/GeoTIFF, Genomic FASTQ, Interview Audio" value="<?= e($old['data_type'] ?? ''); ?>">
                            <p class="form-hint">Specify whether data is qualitative, quantitative, mixed, observational or experimental.</p>
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
                            <input type="text" id="expected_volume" name="expected_volume" class="form-control" placeholder="e.g. 500 MB, 10 GB, ~100,000 observations" value="<?= e($old['expected_volume'] ?? ''); ?>">
                            <p class="form-hint">Estimate total disk space required throughout data collection and post-project phases.</p>
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
                            <textarea id="data_organisation" name="data_organisation" class="form-control" placeholder="Describe file naming conventions (e.g. YYYYMMDD_Project_Exp_v01), directory hierarchy, codebooks and metadata standards (Dublin Core, DataCite)."><?= e($old['data_organisation'] ?? ''); ?></textarea>
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
                            <input type="text" id="storage_location" name="storage_location" class="form-control" placeholder="e.g. Institutional High-Performance Cluster, Secure Cloud Repository, Encrypted Local NAS" value="<?= e($old['storage_location'] ?? ''); ?>">
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
                            <textarea id="protection_measures" name="protection_measures" class="form-control" placeholder="Describe data encryption at rest/in transit, password policies, automated backup intervals (e.g. daily/weekly snapshots)."><?= e($old['protection_measures'] ?? ''); ?></textarea>
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
                            <textarea id="access_control" name="access_control" class="form-control" placeholder="Who will have access to the raw and processed data? How will access permissions be authenticated and audited?"><?= e($old['access_control'] ?? ''); ?></textarea>
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
                            <textarea id="sensitive_data_handling" name="sensitive_data_handling" class="form-control" placeholder="Describe pseudonymization, anonymization, GDPR / Institutional Ethics compliance or state 'No sensitive or personal data involved'."><?= e($old['sensitive_data_handling'] ?? ''); ?></textarea>
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
                            <textarea id="sharing_plan" name="sharing_plan" class="form-control" placeholder="How and when will the data be made accessible? (e.g. Open Access under CC-BY 4.0 after 12-month embargo, Restricted upon request)."><?= e($old['sharing_plan'] ?? ''); ?></textarea>
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
                            <input type="text" id="retention_period" name="retention_period" class="form-control" placeholder="e.g. 5 years post-publication, 10 years as per funder mandate, Permanent" value="<?= e($old['retention_period'] ?? ''); ?>">
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
                            <textarea id="preservation_plan" name="preservation_plan" class="form-control" placeholder="How will data be preserved long-term? (e.g. Conversion to open standard formats such as CSV, PDF/A, preservation in institutional repository)."><?= e($old['preservation_plan'] ?? ''); ?></textarea>
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
                            <textarea id="disposal_plan" name="disposal_plan" class="form-control" placeholder="Describe secure cryptographic erasure, media degaussing or institutional data purging policies once the retention period concludes."><?= e($old['disposal_plan'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="form-actions">
                        <a href="index.php" class="btn-cancel">Cancel</a>
                        <button type="submit" name="submit_action" value="draft" class="btn-draft">
                            💾 Save Draft
                        </button>
                        <button type="submit" name="submit_action" value="submit" class="btn-submit">
                            🚀 Submit for Review &rarr;
                        </button>
                    </div>

                </form>

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

</body>
</html>
