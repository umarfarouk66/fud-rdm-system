<?php
/**
 * Upload New Dataset Version Controller & View
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

$datasetId = (int)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['dataset_id'] ?? 0) : ($_GET['id'] ?? 0));

if ($datasetId <= 0) {
    $_SESSION['dataset_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

// Fetch dataset details
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.project_code,
            p.title AS project_title
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        $_SESSION['dataset_error'] = 'The requested dataset does not exist.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce edit authorization
    if (!canEditDataset($projectRole, $systemRole, $ownerId, $userId)) {
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to upload new versions for this dataset.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Dataset Version Upload Auth Error: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'Database error verifying dataset permissions.';
    header('Location: index.php');
    exit;
}

$nextVersion = (int)$dataset['current_version'] + 1;

// -------------------------------------------------------------
// POST Request: Process New Version Upload
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['version_errors'] = ['Security validation failed (invalid CSRF token).'];
        header("Location: upload.php?id={$datasetId}");
        exit;
    }

    $versionNotes = sanitize_input($_POST['version_notes'] ?? '');
    $fileValidation = validateUploadedFile($_FILES['version_file'] ?? []);

    if (!$fileValidation['valid']) {
        $_SESSION['version_errors'] = [$fileValidation['error']];
        header("Location: upload.php?id={$datasetId}");
        exit;
    }

    $savedFile = null;

    try {
        // Save uploaded file into storage/datasets/
        $savedFile = saveUploadedDatasetFile($fileValidation['temp_path'], $fileValidation['extension']);

        $pdo->beginTransaction();

        // 1. Insert into dataset_versions
        $vSql = "
            INSERT INTO dataset_versions (
                dataset_id,
                version_number,
                file_name,
                stored_file_name,
                file_path,
                file_size,
                mime_type,
                checksum,
                uploaded_by,
                version_notes
            ) VALUES (
                :dataset_id,
                :version_number,
                :file_name,
                :stored_file_name,
                :file_path,
                :file_size,
                :mime_type,
                :checksum,
                :uploaded_by,
                :version_notes
            )
        ";

        $vStmt = $pdo->prepare($vSql);
        $vStmt->execute([
            ':dataset_id'        => $datasetId,
            ':version_number'    => $nextVersion,
            ':file_name'         => $fileValidation['original_name'],
            ':stored_file_name'  => $savedFile['stored_file_name'],
            ':file_path'         => $savedFile['file_path'],
            ':file_size'         => $fileValidation['size'],
            ':mime_type'         => $fileValidation['mime'],
            ':checksum'          => $savedFile['checksum'],
            ':uploaded_by'       => $userId,
            ':version_notes'     => !empty($versionNotes) ? $versionNotes : "Version {$nextVersion} update"
        ]);

        // 2. Update datasets current version & total size
        $dSql = "
            UPDATE datasets SET
                current_version = :next_version,
                total_size      = total_size + :file_size,
                updated_at      = CURRENT_TIMESTAMP
            WHERE id = :id
        ";
        $dStmt = $pdo->prepare($dSql);
        $dStmt->execute([
            ':next_version' => $nextVersion,
            ':file_size'    => $fileValidation['size'],
            ':id'           => $datasetId
        ]);

        // 3. Record audit log
        log_audit(
            $pdo,
            'dataset_version_uploaded',
            'dataset',
            $datasetId,
            "Uploaded version {$nextVersion} ({$fileValidation['original_name']}, " . formatFileSize($fileValidation['size']) . ") to dataset '{$dataset['title']}'"
        );

        $pdo->commit();

        $_SESSION['dataset_success'] = "Version {$nextVersion} uploaded successfully!";
        header("Location: view.php?id={$datasetId}");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($savedFile && file_exists($savedFile['full_path'])) {
            unlink($savedFile['full_path']);
        }
        error_log("Version Upload Exception: " . $e->getMessage());
        $_SESSION['version_errors'] = ['A database error occurred while uploading the new version. Please try again.'];
        header("Location: upload.php?id={$datasetId}");
        exit;
    }
}

// -------------------------------------------------------------
// GET Request: Render Upload New Version View
// -------------------------------------------------------------
$errors = $_SESSION['version_errors'] ?? [];
unset($_SESSION['version_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Version <?= $nextVersion; ?> — <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-sm);
            max-width: 800px;
            margin: 0 auto 3rem auto;
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
        .upload-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: var(--radius-md);
            padding: 2rem;
            text-align: center;
            background: #f8fafc;
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

        <!-- Top Breadcrumb -->
        <div style="margin-bottom: 1.5rem;">
            <a href="view.php?id=<?= (int)$dataset['id']; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Dataset View
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); font-size: 0.9rem;">
                    <?= e($dataset['project_code']); ?> &bull; Current: v<?= (int)$dataset['current_version']; ?>
                </span>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem; margin-bottom: 0.4rem;">
                    Upload New Dataset Version (v<?= $nextVersion; ?>)
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Deposit updated data file for <strong><?= e($dataset['title']); ?></strong>. Previous versions will remain preserved and accessible.
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

            <!-- Version Upload Form -->
            <form action="upload.php" method="POST" enctype="multipart/form-data" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="dataset_id" value="<?= (int)$dataset['id']; ?>">

                <div class="form-group">
                    <label for="version_file" class="form-label">New Data File (v<?= $nextVersion; ?>) <span class="required">*</span></label>
                    <div class="upload-dropzone">
                        <input 
                            type="file" 
                            id="version_file" 
                            name="version_file" 
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

                <div class="form-group">
                    <label for="version_notes" class="form-label">Version Notes / Changelog</label>
                    <textarea 
                        id="version_notes" 
                        name="version_notes" 
                        class="form-control" 
                        placeholder="Describe revisions (e.g. Corrected missing observation values, appended Q2 participants, updated codebook...)"
                    ></textarea>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.35rem;">
                        Help collaborators and peer reviewers understand what changed between version <?= (int)$dataset['current_version']; ?> and version <?= $nextVersion; ?>.
                    </p>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <a href="view.php?id=<?= (int)$dataset['id']; ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">
                        Upload Version <?= $nextVersion; ?> &rarr;
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
