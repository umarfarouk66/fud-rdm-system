<?php
/**
 * Create Dataset Preservation Record View
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/preservation_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Enforce librarian / admin authorization
if (!canManagePreservation($systemRole)) {
    $_SESSION['access_error'] = 'Access denied: Dataset preservation and archiving is restricted to librarians and system administrators.';
    header('Location: ../researcher/dashboard.php');
    exit;
}

$datasetId = (int)($_GET['dataset_id'] ?? 0);
$selectedVersionNumber = (int)($_GET['version'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['preservation_error'] = 'Please select a valid dataset to preserve.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and project details
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            p.department AS project_department,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['preservation_error'] = 'The requested dataset does not exist or has been deleted.';
        header('Location: index.php');
        exit;
    }

    // 2. Fetch all versions for this dataset
    $vStmt = $pdo->prepare("
        SELECT * FROM dataset_versions 
        WHERE dataset_id = :dataset_id 
        ORDER BY version_number DESC
    ");
    $vStmt->execute([':dataset_id' => $datasetId]);
    $versions = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($versions)) {
        $_SESSION['preservation_error'] = 'This dataset has no uploaded file versions to preserve.';
        header('Location: index.php');
        exit;
    }

    // Default to current version if not specified
    if ($selectedVersionNumber <= 0) {
        $selectedVersionNumber = (int)$dataset['current_version'];
    }

    $activeVersion = null;
    foreach ($versions as $v) {
        if ((int)$v['version_number'] === $selectedVersionNumber) {
            $activeVersion = $v;
            break;
        }
    }
    if (!$activeVersion) {
        $activeVersion = $versions[0];
        $selectedVersionNumber = (int)$activeVersion['version_number'];
    }

} catch (PDOException $e) {
    error_log("Preservation Create Form Error: " . $e->getMessage());
    $_SESSION['preservation_error'] = 'Database error retrieving dataset version details.';
    header('Location: index.php');
    exit;
}

// Flash errors and old input
$errors = $_SESSION['preservation_errors'] ?? [];
$old    = $_SESSION['old_preservation_data'] ?? [];
unset($_SESSION['preservation_errors'], $_SESSION['old_preservation_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preserve Dataset: <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-sm);
            max-width: 850px;
            margin: 0 auto 3rem auto;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .checksum-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.75rem 1rem;
            font-family: monospace;
            font-size: 0.85rem;
            color: #1e293b;
            word-break: break-all;
            margin-top: 0.35rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.4rem;
        }
        .form-label .required {
            color: #dc2626;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem 0.9rem;
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
            min-height: 110px;
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

        <!-- Top Breadcrumb -->
        <div style="margin-bottom: 1.5rem; max-width: 850px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Preservation Directory
            </a>
        </div>

        <div class="form-card">
            <div style="margin-bottom: 2rem;">
                <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); font-size: 0.9rem;">
                    <?= e($dataset['project_code']); ?>
                </span>
                <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem; margin-bottom: 0.4rem;">
                    Execute Dataset Preservation
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Perform cryptographic SHA-256 checksum verification and generate an immutable long-term preservation record.
                </p>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 1.5rem;">
                    <strong>Preservation Validation Errors:</strong>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="store.php" method="POST" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="dataset_id" value="<?= (int)$dataset['id']; ?>">

                <!-- Version Selection -->
                <div class="form-group">
                    <label for="version_number" class="form-label">
                        Dataset Version to Preserve <span class="required">*</span>
                    </label>
                    <select id="version_number" name="version_number" class="form-control" onchange="window.location.href='create.php?dataset_id=<?= (int)$dataset['id']; ?>&version=' + this.value;">
                        <?php foreach ($versions as $v): ?>
                            <option value="<?= (int)$v['version_number']; ?>" <?= ((int)$v['version_number'] === $selectedVersionNumber) ? 'selected' : ''; ?>>
                                Version <?= (int)$v['version_number']; ?> — <?= e($v['file_name']); ?> (<?= formatFileSize((int)$v['file_size']); ?>) <?= ((int)$v['version_number'] === (int)$dataset['current_version']) ? '[Active Current Version]' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Each version of a research dataset is preserved independently.</p>
                </div>

                <!-- Version Details Info Box -->
                <div class="info-box">
                    <h3 style="font-size: 1.05rem; color: var(--primary-color); margin-bottom: 0.75rem;">
                        📄 Version <?= (int)$activeVersion['version_number']; ?> File & Integrity Details
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">File Name</div>
                            <div style="font-weight: 600;"><?= e($activeVersion['file_name']); ?></div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">File Size</div>
                            <div><?= formatFileSize((int)$activeVersion['file_size']); ?> (<?= (int)$activeVersion['file_size']; ?> bytes)</div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">MIME Type</div>
                            <div><?= e($activeVersion['mime_type']); ?></div>
                        </div>
                    </div>

                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Recorded Baseline SHA-256 Checksum</div>
                        <div class="checksum-box"><?= e($activeVersion['checksum']); ?></div>
                        <p class="form-hint" style="margin-top: 0.35rem;">
                            The system will calculate the live SHA-256 hash of the physical storage file upon submission and strictly verify against this baseline.
                        </p>
                    </div>
                </div>

                <!-- Preservation Location -->
                <div class="form-group">
                    <label for="preservation_location" class="form-label">
                        Preservation Storage Location Tier <span class="required">*</span>
                    </label>
                    <select id="preservation_location" name="preservation_location" class="form-control" required>
                        <?php foreach (PRESERVATION_LOCATIONS as $locKey => $locLabel): ?>
                            <option value="<?= e($locKey); ?>" <?= (($old['preservation_location'] ?? '') === $locKey) ? 'selected' : ''; ?>>
                                <?= e($locLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Preservation Notes -->
                <div class="form-group">
                    <label for="preservation_notes" class="form-label">
                        Preservation & Provenance Notes <span class="required">*</span>
                    </label>
                    <textarea id="preservation_notes" name="preservation_notes" class="form-control" placeholder="Document reasons for preservation, institutional archiving standards compliance and file verification notes..." required><?= e($old['preservation_notes'] ?? 'Integrity verified against baseline SHA-256 checksum for long-term institutional preservation.'); ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit">
                        🛡️ Verify Integrity & Execute Preservation &rarr;
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
