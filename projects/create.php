<?php
/**
 * Create Research Project View
 * RDM Information System - Step 5
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/project_auth.php';

// Enforce authenticated session
requireAuth();

$user = currentUser();
$systemRole = $user['role'];

// Retrieve flash errors & old inputs
$errors = $_SESSION['create_project_errors'] ?? [];
$old    = $_SESSION['old_project_data'] ?? [];
unset($_SESSION['create_project_errors'], $_SESSION['old_project_data']);

// Pre-fill department and faculty if not in old input
$defaultFaculty    = $old['faculty'] ?? ($user['faculty'] ?? '');
$defaultDepartment = $old['department'] ?? ($user['department'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Research Project — FUD RDM System</title>
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
        textarea.form-control {
            min-height: 100px;
            font-family: inherit;
            resize: vertical;
        }
        .form-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
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

    <!-- Main Content -->
    <main class="dash-container">

        <!-- Top Breadcrumb -->
        <div style="margin-bottom: 1.5rem;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Projects Directory
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.4rem;">
                    Register New Research Project
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Provide project metadata, research methodologies and institutional governance details.
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

            <!-- Project Form -->
            <form action="store.php" method="POST" autocomplete="off">
                <?= csrf_field(); ?>

                <!-- Section 1: Overview -->
                <h2 class="form-section-title">1. Project Overview</h2>

                <div class="form-group">
                    <label for="title" class="form-label">Project Title <span class="required">*</span></label>
                    <input 
                        type="text" 
                        id="title" 
                        name="title" 
                        class="form-control" 
                        placeholder="e.g. Investigation of Machine Learning in Environmental Geospatial Modeling"
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
                        placeholder="Detailed background and summary of the research study..."
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
                            placeholder="e.g. Computer Science / Earth Sciences"
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
                            placeholder="e.g. Faculty of Natural & Applied Sciences"
                            value="<?= e($defaultFaculty); ?>" 
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
                        placeholder="e.g. Department of Informatics"
                        value="<?= e($defaultDepartment); ?>" 
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
                        placeholder="Core goals, hypotheses and intended scientific outcomes..."
                        required
                    ><?= e($old['objectives'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="methodology" class="form-label">Research Methodology</label>
                    <textarea 
                        id="methodology" 
                        name="methodology" 
                        class="form-control" 
                        placeholder="Experimental protocols, sampling strategies, computational models..."
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
                            placeholder="e.g. CSV, NetCDF, GeoTIFF, Genomic FASTA, Audio"
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
                            placeholder="e.g. Laboratory 3B, North Sea Buoy Array, Public Archives"
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
                            value="<?= e($old['start_date'] ?? date('Y-m-d')); ?>" 
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
                        placeholder="e.g. EPSRC Grant Ref: EP/V01234/1, Horizon Europe"
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
                        placeholder="e.g. Institutional Ethics Review Board Ref: ERB-2026-88"
                        value="<?= e($old['ethical_approval_information'] ?? ''); ?>"
                    >
                </div>

                <!-- Action Buttons -->
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">Register Project &rarr;</button>
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
