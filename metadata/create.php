<?php
/**
 * Create Dataset Metadata View
 * RDM Information System - Step 8
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/metadata_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$selectedDatasetId = (int)($_GET['dataset_id'] ?? 0);

// Check if selected dataset already has metadata
if ($selectedDatasetId > 0) {
    try {
        $checkStmt = $pdo->prepare("SELECT id FROM dataset_metadata WHERE dataset_id = :dataset_id LIMIT 1");
        $checkStmt->execute([':dataset_id' => $selectedDatasetId]);
        $existingMeta = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingMeta) {
            $_SESSION['metadata_error'] = 'This dataset already has associated metadata.';
            header("Location: view.php?id={$existingMeta['id']}");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Metadata Check Error: " . $e->getMessage());
    }
}

// Fetch eligible datasets where user is owner/manager that have no metadata yet
$eligibleDatasets = [];
try {
    if ($systemRole === 'admin') {
        $stmt = $pdo->query("
            SELECT 
                d.id, d.title, d.dataset_type, d.access_level,
                p.project_code,
                v.file_name AS latest_file_name,
                v.mime_type AS latest_mime
            FROM datasets d
            INNER JOIN research_projects p ON d.project_id = p.id
            LEFT JOIN dataset_versions v ON d.id = v.dataset_id AND d.current_version = v.version_number
            WHERE d.id NOT IN (SELECT dataset_id FROM dataset_metadata)
            ORDER BY d.created_at DESC
        ");
        $eligibleDatasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                d.id, d.title, d.dataset_type, d.access_level,
                p.project_code,
                v.file_name AS latest_file_name,
                v.mime_type AS latest_mime
            FROM datasets d
            INNER JOIN research_projects p ON d.project_id = p.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            LEFT JOIN dataset_versions v ON d.id = v.dataset_id AND d.current_version = v.version_number
            WHERE (d.owner_id = :user_id OR (pm.user_id = :user_id AND pm.role IN ('owner', 'manager')))
              AND d.id NOT IN (SELECT dataset_id FROM dataset_metadata)
            GROUP BY d.id
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([':user_id' => $userId]);
        $eligibleDatasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Eligible Datasets Query Error: " . $e->getMessage());
}

// Retrieve flash errors & old input
$errors = $_SESSION['create_metadata_errors'] ?? [];
$old    = $_SESSION['old_metadata_data'] ?? [];
unset($_SESSION['create_metadata_errors'], $_SESSION['old_metadata_data']);

$activeDatasetId = !empty($old['dataset_id']) ? (int)$old['dataset_id'] : $selectedDatasetId;

// Pre-fill suggested values
$suggestedCreator = !empty($old['creator']) ? $old['creator'] : ($user['name'] . ', ' . ($user['department'] ?: $user['institution']));
$suggestedFormat = $old['file_format'] ?? '';
$suggestedType = $old['data_type'] ?? '';

if ($activeDatasetId > 0 && empty($suggestedFormat)) {
    foreach ($eligibleDatasets as $ed) {
        if ((int)$ed['id'] === $activeDatasetId) {
            $ext = strtolower(pathinfo($ed['latest_file_name'] ?? '', PATHINFO_EXTENSION));
            $suggestedFormat = strtoupper($ext ?: 'CSV');
            if (empty($suggestedType)) {
                $suggestedType = $ed['dataset_type'] ?: 'Quantitative';
            }
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Dataset Metadata — FUD RDM System</title>
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
        .section-badge {
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
            min-height: 85px;
            font-family: inherit;
            resize: vertical;
        }
        .form-hint {
            font-size: 0.8rem;
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

        <!-- Breadcrumb -->
        <div style="margin-bottom: 1.5rem;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Metadata Directory
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.4rem;">
                    Create Dataset Descriptive Metadata
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Record methodological provenance, licensing terms and discoverability keywords for research data.
                </p>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 2rem;">
                    <strong>Please address the following errors:</strong>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($eligibleDatasets)): ?>
                <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1.5rem; border-radius: var(--radius-md); text-align: center;">
                    <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;">No Eligible Datasets Available</h3>
                    <p style="font-size: 0.9rem; margin-bottom: 1rem;">
                        All your active datasets already have metadata records, or you haven't deposited any datasets yet.
                    </p>
                    <a href="../datasets/create.php" class="btn-action btn-action-primary" style="padding: 0.6rem 1.2rem;">
                        ➕ Deposit New Dataset
                    </a>
                </div>
            <?php else: ?>

                <!-- Metadata Form -->
                <form action="store.php" method="POST" autocomplete="off">
                    <?= csrf_field(); ?>

                    <!-- Dataset Selection -->
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius-md); padding: 1.5rem; margin-bottom: 2rem;">
                        <label for="dataset_id" class="form-label" style="color: var(--primary-color); font-size: 0.95rem;">
                            Associated Research Dataset <span class="required">*</span>
                        </label>
                        <select id="dataset_id" name="dataset_id" class="form-control" onchange="window.location.href='create.php?dataset_id=' + this.value;" required>
                            <option value="">-- Choose Deposited Dataset --</option>
                            <?php foreach ($eligibleDatasets as $ed): ?>
                                <option value="<?= (int)$ed['id']; ?>" <?= ($activeDatasetId === (int)$ed['id']) ? 'selected' : ''; ?>>
                                    <?= e($ed['project_code']); ?> — <?= e($ed['title']); ?> (Access: <?= e($ed['access_level']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Each deposited dataset maintains exactly one primary metadata record.</p>
                    </div>

                    <!-- Section A: Identification -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 1</span>
                            <span class="section-title-text">Dataset Identification</span>
                        </div>
                        <div class="form-group">
                            <label for="creator" class="form-label">Creator / Principal Investigators <span class="required">*</span></label>
                            <input type="text" id="creator" name="creator" class="form-control" placeholder="e.g. Dr. John Doe (Department of Computer Science)" value="<?= e($suggestedCreator); ?>" required maxlength="255">
                        </div>

                        <div class="form-group">
                            <label for="keywords" class="form-label">Keywords / Subject Tags <span class="required">*</span></label>
                            <input type="text" id="keywords" name="keywords" class="form-control" placeholder="e.g. machine learning, telemetry, robotics, sensor fusion, Nigeria" value="<?= e($old['keywords'] ?? ''); ?>" required>
                            <p class="form-hint">Comma-separated keywords facilitating research discovery and indexing.</p>
                        </div>

                        <div class="form-group">
                            <label for="subject_area" class="form-label">Subject Area / Domain <span class="required">*</span></label>
                            <input type="text" id="subject_area" name="subject_area" class="form-control" placeholder="e.g. Computer Science, Artificial Intelligence, Robotics" value="<?= e($old['subject_area'] ?? ''); ?>" required maxlength="255">
                        </div>
                    </div>

                    <!-- Section B: Dates -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 2</span>
                            <span class="section-title-text">Dataset Timeline & Dates</span>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="creation_date" class="form-label">Creation / Collection Date <span class="required">*</span></label>
                                <input type="date" id="creation_date" name="creation_date" class="form-control" value="<?= e($old['creation_date'] ?? date('Y-m-d')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="modification_date" class="form-label">Modification Date <span style="color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></label>
                                <input type="date" id="modification_date" name="modification_date" class="form-control" value="<?= e($old['modification_date'] ?? ''); ?>">
                                <p class="form-hint">Must not be earlier than the creation date.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Section C: Methodology & Coverage -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 3</span>
                            <span class="section-title-text">Methodology & Data Characteristics</span>
                        </div>
                        <div class="form-group">
                            <label for="collection_methodology" class="form-label">Collection Methodology <span class="required">*</span></label>
                            <textarea id="collection_methodology" name="collection_methodology" class="form-control" placeholder="Describe instruments, surveys, experimental protocols, sampling techniques..." required><?= e($old['collection_methodology'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="geographic_coverage" class="form-label">Geographic Coverage <span class="required">*</span></label>
                                <input type="text" id="geographic_coverage" name="geographic_coverage" class="form-control" placeholder="e.g. Katsina State, Nigeria; Global; Laboratory Setting" value="<?= e($old['geographic_coverage'] ?? ''); ?>" required maxlength="255">
                            </div>
                            <div class="form-group">
                                <label for="data_type" class="form-label">Data Type <span class="required">*</span></label>
                                <input type="text" id="data_type" name="data_type" class="form-control" placeholder="e.g. Quantitative, Geospatial, Survey, Experimental" value="<?= e($suggestedType); ?>" required maxlength="255">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="file_format" class="form-label">File Format / Encoding <span class="required">*</span></label>
                            <input type="text" id="file_format" name="file_format" class="form-control" placeholder="e.g. CSV (UTF-8), XLSX, NetCDF-4, GeoTIFF" value="<?= e($suggestedFormat); ?>" required maxlength="100">
                        </div>
                    </div>

                    <!-- Section D: Publication & Funding -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 4</span>
                            <span class="section-title-text">Publications & Funding (Optional)</span>
                        </div>
                        <div class="form-group">
                            <label for="related_publication" class="form-label">Related Publication / DOI Reference</label>
                            <textarea id="related_publication" name="related_publication" class="form-control" placeholder="e.g. DOI: 10.1016/j.jvolgeo.2026.107000 or Paper citation..."><?= e($old['related_publication'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="funding_source" class="form-label">Funding Source / Grant Information</label>
                            <textarea id="funding_source" name="funding_source" class="form-control" placeholder="e.g. Institutional Research Grant #RG-2026-CS-04"><?= e($old['funding_source'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Section E: Licensing & Access -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 5</span>
                            <span class="section-title-text">Licensing & Access Governance</span>
                        </div>
                        <div class="form-group">
                            <label for="license" class="form-label">Data License <span class="required">*</span></label>
                            <select id="license" name="license" class="form-control" required>
                                <option value="">-- Choose Data License --</option>
                                <?php foreach (METADATA_LICENSE_OPTIONS as $licKey => $licLabel): ?>
                                    <option value="<?= e($licKey); ?>" <?= (($old['license'] ?? '') === $licKey) ? 'selected' : ''; ?>>
                                        <?= e($licLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="access_conditions" class="form-label">Access Conditions & Terms of Use <span class="required">*</span></label>
                            <textarea id="access_conditions" name="access_conditions" class="form-control" placeholder="Explain who can access the dataset, citation requirements and any usage restrictions..." required><?= e($old['access_conditions'] ?? ''); ?></textarea>
                            <p class="form-hint">Should be consistent with the associated dataset access level (Private, Restricted, or Public).</p>
                        </div>
                    </div>

                    <!-- Section F: Processing & Provenance -->
                    <div class="form-section-box">
                        <div class="section-header">
                            <span class="section-badge">Section 6</span>
                            <span class="section-title-text">Processing & Provenance Documentation</span>
                        </div>
                        <div class="form-group">
                            <label for="processing_documentation" class="form-label">Data Cleaning, Transformations & Derived Variables <span class="required">*</span></label>
                            <textarea id="processing_documentation" name="processing_documentation" class="form-control" style="min-height: 120px;" placeholder="Document step-by-step data cleaning, missing value imputation, outlier handling, software scripts used and derived features..." required><?= e($old['processing_documentation'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="form-actions">
                        <a href="index.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">
                            Save Metadata Record &rarr;
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
