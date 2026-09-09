<?php
/**
 * Create / Deposit Dataset View
 * RDM Information System - Step 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$selectedProjectId = (int)($_GET['project_id'] ?? 0);

// Fetch eligible projects where user is owner or manager
$eligibleProjects = [];
try {
    if ($systemRole === 'admin') {
        $stmt = $pdo->query("
            SELECT p.id, p.project_code, p.title, dmp.id AS dmp_id, dmp.status AS dmp_status
            FROM research_projects p 
            LEFT JOIN data_management_plans dmp ON p.id = dmp.project_id
            ORDER BY p.title ASC
        ");
        $eligibleProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id, p.project_code, p.title, dmp.id AS dmp_id, dmp.status AS dmp_status
            FROM research_projects p 
            LEFT JOIN project_members pm ON p.id = pm.project_id 
            LEFT JOIN data_management_plans dmp ON p.id = dmp.project_id
            WHERE p.owner_id = :user_id OR (pm.user_id = :user_id AND pm.role IN ('owner', 'manager'))
            ORDER BY p.title ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $eligibleProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Eligible Projects Fetch Error: " . $e->getMessage());
}

// Retrieve flash errors & old inputs
$errors = $_SESSION['create_dataset_errors'] ?? [];
$old    = $_SESSION['old_dataset_data'] ?? [];
unset($_SESSION['create_dataset_errors'], $_SESSION['old_dataset_data']);

$activeProjectId = !empty($old['project_id']) ? (int)$old['project_id'] : $selectedProjectId;

// Check DMP status for currently selected project
$currentProjectDmp = null;
if ($activeProjectId > 0) {
    foreach ($eligibleProjects as $ep) {
        if ((int)$ep['id'] === $activeProjectId) {
            $currentProjectDmp = [
                'has_dmp' => !empty($ep['dmp_id']),
                'status'  => $ep['dmp_status'] ?? null
            ];
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
    <title>Deposit Research Dataset — FUD RDM System</title>
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
            min-height: 90px;
            font-family: inherit;
            resize: vertical;
        }
        .form-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
        }
        .upload-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: var(--radius-md);
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover {
            border-color: var(--accent-color);
            background: #eff6ff;
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
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Datasets Repository
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.4rem;">
                    Deposit Research Dataset & Initial Version (v1)
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Upload research files, establish cryptographic checksums and set repository governance controls.
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

            <?php if (empty($eligibleProjects)): ?>
                <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1.5rem; border-radius: var(--radius-md); text-align: center;">
                    <h3 style="margin-bottom: 0.5rem; font-size: 1.1rem;">No Eligible Research Projects Available</h3>
                    <p style="font-size: 0.9rem; margin-bottom: 1rem;">
                        You must be an owner or manager of an active research project to deposit datasets.
                    </p>
                    <a href="../projects/create.php" class="btn-action btn-action-primary" style="padding: 0.6rem 1.2rem;">
                        ➕ Register New Project
                    </a>
                </div>
            <?php else: ?>

                <!-- DMP Prerequisite Warnings -->
                <?php if ($currentProjectDmp && !$currentProjectDmp['has_dmp']): ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.35rem; font-weight: 700;">⚠️ Data Management Plan Required</h4>
                        <p style="font-size: 0.9rem; margin-bottom: 0.75rem;">
                            A Data Management Plan must be created before depositing a dataset for this research project.
                        </p>
                        <a href="../dmp/create.php?project_id=<?= $activeProjectId; ?>" style="color: #991b1b; font-weight: 700; text-decoration: underline; font-size: 0.9rem;">
                            Create DMP for this Project &rarr;
                        </a>
                    </div>
                <?php elseif ($currentProjectDmp && $currentProjectDmp['status'] === 'draft'): ?>
                    <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <h4 style="margin-bottom: 0.35rem; font-weight: 700;">💡 DMP Status Notice</h4>
                        <p style="font-size: 0.9rem;">
                            Your Data Management Plan is currently in <strong>Draft</strong> status. Consider completing and submitting it for review.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Dataset Form -->
                <form action="store.php" method="POST" enctype="multipart/form-data" autocomplete="off">
                    <?= csrf_field(); ?>

                    <!-- Section 1: Associated Project -->
                    <h2 class="form-section-title">1. Project Affiliation</h2>

                    <div class="form-group">
                        <label for="project_id" class="form-label">Associated Research Project <span class="required">*</span></label>
                        <select id="project_id" name="project_id" class="form-control" onchange="window.location.href='create.php?project_id=' + this.value;" required>
                            <option value="">-- Select Research Project --</option>
                            <?php foreach ($eligibleProjects as $ep): ?>
                                <option value="<?= (int)$ep['id']; ?>" <?= ($activeProjectId === (int)$ep['id']) ? 'selected' : ''; ?>>
                                    <?= e($ep['project_code']); ?> — <?= e($ep['title']); ?> 
                                    <?= !empty($ep['dmp_id']) ? " [DMP: {$ep['dmp_status']}]" : " [No DMP]"; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Datasets must be linked to an approved institutional research project.</p>
                    </div>

                    <!-- Section 2: Dataset Information -->
                    <h2 class="form-section-title">2. Dataset Information</h2>

                    <div class="form-group">
                        <label for="title" class="form-label">Dataset Title <span class="required">*</span></label>
                        <input 
                            type="text" 
                            id="title" 
                            name="title" 
                            class="form-control" 
                            placeholder="e.g. Environmental Sensor Stream Observations (Q1 2026)"
                            value="<?= e($old['title'] ?? ''); ?>" 
                            required
                            maxlength="255"
                        >
                    </div>

                    <div class="form-group">
                        <label for="description" class="form-label">Dataset Description <span class="required">*</span></label>
                        <textarea 
                            id="description" 
                            name="description" 
                            class="form-control" 
                            placeholder="Describe collection methodology, data variables, experimental parameters..."
                            required
                        ><?= e($old['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="dataset_type" class="form-label">Primary Dataset Type <span class="required">*</span></label>
                            <select id="dataset_type" name="dataset_type" class="form-control" required>
                                <option value="">-- Choose Data Format / Type --</option>
                                <option value="CSV / Tabular Data" <?= (($old['dataset_type'] ?? '') === 'CSV / Tabular Data') ? 'selected' : ''; ?>>CSV / Tabular Data</option>
                                <option value="Excel Spreadsheet (.xlsx)" <?= (($old['dataset_type'] ?? '') === 'Excel Spreadsheet (.xlsx)') ? 'selected' : ''; ?>>Excel Spreadsheet (.xlsx)</option>
                                <option value="JSON Data" <?= (($old['dataset_type'] ?? '') === 'JSON Data') ? 'selected' : ''; ?>>JSON Data</option>
                                <option value="XML Data" <?= (($old['dataset_type'] ?? '') === 'XML Data') ? 'selected' : ''; ?>>XML Data</option>
                                <option value="Statistical Data (SPSS/Stata/R)" <?= (($old['dataset_type'] ?? '') === 'Statistical Data (SPSS/Stata/R)') ? 'selected' : ''; ?>>Statistical Data (SPSS/Stata/R)</option>
                                <option value="Geospatial / NetCDF / GeoTIFF" <?= (($old['dataset_type'] ?? '') === 'Geospatial / NetCDF / GeoTIFF') ? 'selected' : ''; ?>>Geospatial / NetCDF / GeoTIFF</option>
                                <option value="Genomic / Sequence Data (FASTA/VCF)" <?= (($old['dataset_type'] ?? '') === 'Genomic / Sequence Data (FASTA/VCF)') ? 'selected' : ''; ?>>Genomic / Sequence Data (FASTA/VCF)</option>
                                <option value="Text / Plain Text (.txt)" <?= (($old['dataset_type'] ?? '') === 'Text / Plain Text (.txt)') ? 'selected' : ''; ?>>Text / Plain Text (.txt)</option>
                                <option value="Compressed Archive (.zip/.tar.gz)" <?= (($old['dataset_type'] ?? '') === 'Compressed Archive (.zip/.tar.gz)') ? 'selected' : ''; ?>>Compressed Archive (.zip/.tar.gz)</option>
                                <option value="Image Collection" <?= (($old['dataset_type'] ?? '') === 'Image Collection') ? 'selected' : ''; ?>>Image Collection</option>
                                <option value="Audio / Video Media" <?= (($old['dataset_type'] ?? '') === 'Audio / Video Media') ? 'selected' : ''; ?>>Audio / Video Media</option>
                                <option value="Other Format" <?= (($old['dataset_type'] ?? '') === 'Other Format') ? 'selected' : ''; ?>>Other Format</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="access_level" class="form-label">Repository Access Level <span class="required">*</span></label>
                            <select id="access_level" name="access_level" class="form-control" required>
                                <option value="private" <?= (($old['access_level'] ?? 'private') === 'private') ? 'selected' : ''; ?>>
                                    Private (Restricted to project collaborators only)
                                </option>
                                <option value="restricted" <?= (($old['access_level'] ?? '') === 'restricted') ? 'selected' : ''; ?>>
                                    Restricted (Institutional access / Approval required)
                                </option>
                                <option value="public" <?= (($old['access_level'] ?? '') === 'public') ? 'selected' : ''; ?>>
                                    Public (Open Access via controlled repository download)
                                </option>
                            </select>
                            <p class="form-hint">Default setting is Private. Can be changed upon publication.</p>
                        </div>
                    </div>

                    <!-- Section 3: File Upload -->
                    <h2 class="form-section-title">3. Initial File Deposit (Version 1)</h2>

                    <div class="form-group">
                        <label for="dataset_file" class="form-label">Select Dataset File <span class="required">*</span></label>
                        <div class="upload-dropzone">
                            <input 
                                type="file" 
                                id="dataset_file" 
                                name="dataset_file" 
                                class="form-control" 
                                required
                                style="max-width: 400px; margin: 0 auto 0.75rem auto;"
                            >
                            <p style="font-size: 0.85rem; color: var(--text-muted);">
                                <strong>Allowed Formats:</strong> .csv, .xlsx, .json, .xml, .txt, .pdf, .zip, .tar.gz, .sav, .dta, .rds, .parquet, .fasta, .fastq, .vcf, .nc, .tif
                                <br>
                                <strong>Maximum File Size:</strong> 100 MB &bull; SHA-256 integrity checksum will be generated automatically.
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="form-actions">
                        <a href="index.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">
                            Deposit Dataset & Generate v1 &rarr;
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
