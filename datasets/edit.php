<?php
/**
 * Edit Dataset Metadata View
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

$datasetId = (int)($_GET['id'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['dataset_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

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
        $_SESSION['dataset_error'] = 'The requested dataset was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce edit authorization
    if (!canEditDataset($projectRole, $systemRole, $ownerId, $userId)) {
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to edit this dataset.';
        header("Location: view.php?id={$datasetId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Dataset Edit Query Error: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'Database error verifying dataset permissions.';
    header('Location: index.php');
    exit;
}

// Retrieve flash errors & old input
$errors = $_SESSION['edit_dataset_errors'] ?? [];
$old    = $_SESSION['old_edit_dataset_data'] ?? $dataset;
unset($_SESSION['edit_dataset_errors'], $_SESSION['old_edit_dataset_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Dataset: <?= e($dataset['title']); ?> — FUD RDM System</title>
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
                    <?= e($dataset['project_code']); ?>
                </span>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem; margin-bottom: 0.4rem;">
                    Edit Dataset Metadata
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Update information and access controls for <strong><?= e($dataset['title']); ?></strong>
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

            <!-- Dataset Edit Form -->
            <form action="update.php" method="POST" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="dataset_id" value="<?= (int)$dataset['id']; ?>">

                <div class="form-group">
                    <label for="title" class="form-label">Dataset Title <span class="required">*</span></label>
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
                    <label for="description" class="form-label">Dataset Description <span class="required">*</span></label>
                    <textarea 
                        id="description" 
                        name="description" 
                        class="form-control" 
                        required
                    ><?= e($old['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="dataset_type" class="form-label">Primary Dataset Type <span class="required">*</span></label>
                        <select id="dataset_type" name="dataset_type" class="form-control" required>
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
                    </div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <a href="view.php?id=<?= (int)$dataset['id']; ?>" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">
                        Save Metadata Changes &rarr;
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
