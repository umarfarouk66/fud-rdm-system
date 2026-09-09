<?php
/**
 * Re-verify Dataset Integrity Controller & View
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
    $_SESSION['access_error'] = 'Access denied: Integrity verification is restricted to librarians and system administrators.';
    header('Location: ../researcher/dashboard.php');
    exit;
}

$datasetId = (int)($_GET['dataset_id'] ?? 0);
$versionNumber = (int)($_GET['version'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['preservation_error'] = 'Invalid dataset identifier for verification.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and project details
    $dStmt = $pdo->prepare("
        SELECT 
            d.*,
            p.project_code,
            p.title AS project_title,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['preservation_error'] = 'The requested dataset was not found or has been deleted.';
        header('Location: index.php');
        exit;
    }

    if ($versionNumber <= 0) {
        $versionNumber = (int)$dataset['current_version'];
    }

    // 2. Fetch specific version baseline
    $vStmt = $pdo->prepare("
        SELECT * FROM dataset_versions 
        WHERE dataset_id = :dataset_id AND version_number = :v 
        LIMIT 1
    ");
    $vStmt->execute([':dataset_id' => $datasetId, ':v' => $versionNumber]);
    $version = $vStmt->fetch(PDO::FETCH_ASSOC);

    if (!$version) {
        $_SESSION['preservation_error'] = "Dataset Version {$versionNumber} does not exist.";
        header('Location: index.php');
        exit;
    }

    // 3. Execute live integrity verification
    $integrity = verifyPhysicalFileIntegrity($version['file_path'], $version['checksum'], (int)$version['file_size']);

    // 4. Record audit log
    if ($integrity['valid']) {
        log_audit(
            $pdo,
            'preservation_verified',
            'datasets',
            $datasetId,
            "Librarian '{$user['name']}' (#{$userId}) successfully verified integrity for dataset '{$dataset['title']}' (v{$versionNumber}) - SHA-256 match confirmed [{$integrity['actual_checksum']}]"
        );
        log_audit(
            $pdo,
            'preservation_failed',
            'datasets',
            $datasetId,
            "Librarian '{$user['name']}' (#{$userId}) detected integrity mismatch for dataset '{$dataset['title']}' (v{$versionNumber}): {$integrity['error']}"
        );

        // Alert dataset owner
        require_once __DIR__ . '/../notifications/notification_helper.php';
        createNotification(
            $pdo,
            (int)$dataset['owner_id'],
            'preservation_failed',
            'Dataset Integrity Verification Warning',
            "Integrity check detected a checksum discrepancy for dataset '{$dataset['title']}' (v{$versionNumber}). Preservation review recommended.",
            'preservation',
            $datasetId
        );
    }

} catch (PDOException $e) {
    error_log("Integrity Verification Exception: " . $e->getMessage());
    $_SESSION['preservation_error'] = 'Database error performing integrity verification.';
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrity Verification: <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .verify-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-color);
        }
        .result-banner {
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 2rem;
            border: 1px solid;
        }
        .result-banner-success {
            background: #f0fdf4;
            border-color: #86efac;
            color: #065f46;
        }
        .result-banner-failed {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .result-heading {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section-title {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }
        .info-value {
            font-size: 0.95rem;
            color: var(--text-main);
        }
        .checksum-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.65rem 0.85rem;
            font-family: monospace;
            font-size: 0.85rem;
            word-break: break-all;
            margin-top: 0.25rem;
        }
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 0.6rem 1.25rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            background: #ffffff;
            transition: all 0.15s ease;
        }
        .btn-action:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background-color: var(--primary-light);
        }
        .btn-action-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-action-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
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

        <!-- Top Breadcrumbs -->
        <div style="margin-bottom: 1.5rem; max-width: 900px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Preservation Directory
            </a>
        </div>

        <div class="verify-card">

            <div class="header-top">
                <div>
                    <span style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--accent-color); background: var(--primary-light); padding: 0.25rem 0.5rem; border-radius: var(--radius-sm);">
                        <?= e($dataset['project_code']); ?>
                    </span>
                    <h1 style="font-size: 1.65rem; color: var(--primary-color); font-weight: 700; margin-top: 0.4rem; margin-bottom: 0.25rem;">
                        Integrity Re-Verification: <?= e($dataset['title']); ?> (v<?= (int)$versionNumber; ?>)
                    </h1>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Audited by <?= e($user['name']); ?> on <?= date('F d, Y \a\t H:i:s'); ?>
                    </div>
                </div>
                <div>
                    <a href="history.php?dataset_id=<?= $datasetId; ?>" class="btn-action">
                        📜 View History
                    </a>
                </div>
            </div>

            <!-- Result Banner -->
            <?php if ($integrity['valid']): ?>
                <div class="result-banner result-banner-success">
                    <div class="result-heading">
                        <span>✅</span> Cryptographic Integrity Verification Passed
                    </div>
                    <p style="font-size: 0.95rem; line-height: 1.6;">
                        The physical dataset storage file exactly matches the registered baseline SHA-256 cryptographic checksum and byte length. No file corruption, tampering or silent bitrot was detected.
                    </p>
                </div>
            <?php else: ?>
                <div class="result-banner result-banner-failed">
                    <div class="result-heading">
                        <span>❌</span> Integrity Verification Failed!
                    </div>
                    <p style="font-size: 0.95rem; line-height: 1.6; font-weight: 600;">
                        <?= e($integrity['error']); ?>
                    </p>
                    <p style="font-size: 0.85rem; margin-top: 0.5rem; color: #7f1d1d;">
                        Note: The recorded baseline checksum in the database has NOT been overwritten. The anomaly has been logged to the system audit trail.
                    </p>
                </div>
            <?php endif; ?>

            <!-- Checksum Comparison Box -->
            <div class="section-box">
                <div class="section-title">🔬 SHA-256 Cryptographic Checksum Comparison</div>
                
                <div style="margin-bottom: 1rem;">
                    <div class="info-label">Recorded Database Baseline Checksum:</div>
                    <div class="checksum-box" style="border-left: 4px solid var(--accent-color);">
                        <?= e($version['checksum']); ?>
                    </div>
                </div>

                <div>
                    <div class="info-label">Live Physical File Computed Checksum:</div>
                    <div class="checksum-box" style="border-left: 4px solid <?= $integrity['checksum_match'] ? '#10b981' : '#ef4444'; ?>; color: <?= $integrity['checksum_match'] ? '#065f46' : '#991b1b'; ?>;">
                        <?= e($integrity['actual_checksum'] ?: 'Unreadable / File Missing'); ?>
                    </div>
                </div>
            </div>

            <!-- File Details & Size Box -->
            <div class="section-box">
                <div class="section-title">📦 File Size & Storage Attributes</div>
                <div class="info-grid">
                    <div>
                        <div class="info-label">File Name</div>
                        <div class="info-value"><strong><?= e($version['file_name']); ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Expected Size</div>
                        <div class="info-value"><?= formatFileSize((int)$version['file_size']); ?> (<?= (int)$version['file_size']; ?> bytes)</div>
                    </div>
                    <div>
                        <div class="info-label">Actual Live Size</div>
                        <div class="info-value" style="font-weight: 700; color: <?= $integrity['size_match'] ? '#059669' : '#dc2626'; ?>;">
                            <?= $integrity['actual_size'] !== null ? formatFileSize((int)$integrity['actual_size']) . " ({$integrity['actual_size']} bytes)" : 'N/A'; ?>
                        </div>
                    </div>
                    <div>
                        <div class="info-label">MIME Format</div>
                        <div class="info-value"><?= e($version['mime_type']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="actions-bar">
                <a href="index.php" class="btn-action">
                    &larr; Back to Preservation Directory
                </a>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="verify.php?dataset_id=<?= $datasetId; ?>&version=<?= $versionNumber; ?>" class="btn-action btn-action-primary">
                        🔄 Re-run Check Now
                    </a>
                    <a href="../datasets/view.php?id=<?= $datasetId; ?>" class="btn-action">
                        📊 View Dataset Page
                    </a>
                </div>
            </div>

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
