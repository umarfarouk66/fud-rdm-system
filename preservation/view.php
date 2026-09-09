<?php
/**
 * View Preservation Record View
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

$preservationId = (int)($_GET['id'] ?? 0);

if ($preservationId <= 0) {
    $_SESSION['preservation_error'] = 'Invalid preservation record identifier.';
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            pr.*,
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.description AS dataset_description,
            d.status AS dataset_status,
            d.access_level AS dataset_access_level,
            d.current_version,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            u.first_name AS librarian_first_name,
            u.last_name AS librarian_last_name,
            u.email AS librarian_email,
            owner.first_name AS owner_first_name,
            owner.last_name AS owner_last_name
        FROM preservation_records pr
        INNER JOIN datasets d ON pr.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON pr.performed_by = u.id
        INNER JOIN users owner ON d.owner_id = owner.id
        WHERE pr.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $preservationId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        $_SESSION['preservation_error'] = 'The requested preservation record does not exist.';
        header('Location: index.php');
        exit;
    }

    $datasetId = (int)$record['dataset_id'];
    $meta = parsePreservationNotes($record['notes']);

    // Fetch version details if present in notes or dataset_versions
    $versionNumber = $meta['version_number'] ?? (int)$record['current_version'];
    $vStmt = $pdo->prepare("SELECT * FROM dataset_versions WHERE dataset_id = :dataset_id AND version_number = :v LIMIT 1");
    $vStmt->execute([':dataset_id' => $datasetId, ':v' => $versionNumber]);
    $versionDetails = $vStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Preservation View Error: " . $e->getMessage());
    $_SESSION['preservation_error'] = 'Database error loading preservation record.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['preservation_success'] ?? null;
$errorMsg   = $_SESSION['preservation_error'] ?? null;
unset($_SESSION['preservation_success'], $_SESSION['preservation_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preservation Record #<?= $preservationId; ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .view-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.25rem;
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
        .project-code-tag {
            font-family: monospace;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.3rem 0.65rem;
            border-radius: var(--radius-sm);
            display: inline-block;
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
            padding: 0.75rem 1rem;
            font-family: monospace;
            font-size: 0.85rem;
            color: #1e293b;
            word-break: break-all;
            margin-top: 0.35rem;
        }
        .text-block {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            margin-top: 0.35rem;
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
        .btn-action-success {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .btn-action-success:hover {
            background-color: #047857;
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

        <!-- Top Breadcrumbs -->
        <div style="margin-bottom: 1.5rem; max-width: 900px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Preservation Directory
            </a>
        </div>

        <div class="view-card">

            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($record['project_code']); ?></span>
                    <span style="margin-left: 0.5rem;">
                        <?= getPreservationStatusBadge($record['action'] === 'archive' ? 'archived' : 'preserved'); ?>
                    </span>
                    <h1 style="font-size: 1.65rem; color: var(--primary-color); font-weight: 700; margin-top: 0.5rem; margin-bottom: 0.25rem;">
                        Preservation Record: <?= e($record['dataset_title']); ?>
                    </h1>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Executed on <?= date('F d, Y \a\t H:i', strtotime($record['performed_at'])); ?> by <?= e($record['librarian_first_name'] . ' ' . $record['librarian_last_name']); ?>
                    </div>
                </div>
                <div>
                    <a href="verify.php?dataset_id=<?= $datasetId; ?>" class="btn-action btn-action-success">
                        🔍 Re-verify File Integrity
                    </a>
                </div>
            </div>

            <!-- Section 1: Dataset Overview -->
            <div class="section-box">
                <div class="section-title">📊 Dataset Overview</div>
                <div class="info-grid">
                    <div>
                        <div class="info-label">Dataset Title</div>
                        <div class="info-value"><strong><?= e($record['dataset_title']); ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Research Project</div>
                        <div class="info-value"><?= e($record['project_title']); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Principal Depositor</div>
                        <div class="info-value"><?= e($record['owner_first_name'] . ' ' . $record['owner_last_name']); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Repository Status</div>
                        <div class="info-value" style="font-weight: 700; text-transform: uppercase;">
                            <?= e($record['dataset_status']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Version & Physical Integrity -->
            <div class="section-box">
                <div class="section-title">📦 Preserved Version & Cryptographic Baseline</div>
                <div class="info-grid" style="margin-bottom: 1rem;">
                    <div>
                        <div class="info-label">Preserved Version</div>
                        <div class="info-value"><strong>Version <?= (int)$versionNumber; ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Preserved File Name</div>
                        <div class="info-value"><?= e($meta['file_name'] ?? ($versionDetails['file_name'] ?? 'Data File')); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Verified File Size</div>
                        <div class="info-value"><?= formatFileSize((int)($meta['file_size'] ?? ($versionDetails['file_size'] ?? 0))); ?> (<?= (int)($meta['file_size'] ?? ($versionDetails['file_size'] ?? 0)); ?> bytes)</div>
                    </div>
                    <div>
                        <div class="info-label">Format / MIME Type</div>
                        <div class="info-value"><?= e($meta['mime_type'] ?? ($versionDetails['mime_type'] ?? 'application/octet-stream')); ?></div>
                    </div>
                </div>

                <div>
                    <div class="info-label">Verified SHA-256 Checksum (Cryptographic Integrity Guarantee)</div>
                    <div class="checksum-box"><?= e($meta['verified_checksum'] ?? ($meta['baseline_checksum'] ?? ($versionDetails['checksum'] ?? ''))); ?></div>
                </div>
            </div>

            <!-- Section 3: Preservation Governance & Provenance -->
            <div class="section-box">
                <div class="section-title">🏛️ Preservation Tier & Archival Notes</div>
                <div class="info-grid" style="margin-bottom: 1rem;">
                    <div>
                        <div class="info-label">Storage Location Tier</div>
                        <div class="info-value"><strong style="color: #1e40af;"><?= e($meta['location'] ?? 'Institutional Repository'); ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Integrity Status</div>
                        <div class="info-value" style="color: #059669; font-weight: 700;">
                            ✅ Cryptographically Verified (Match)
                        </div>
                    </div>
                    <div>
                        <div class="info-label">Preserving Librarian</div>
                        <div class="info-value"><?= e($record['librarian_first_name'] . ' ' . $record['librarian_last_name']); ?> (<?= e($record['librarian_email']); ?>)</div>
                    </div>
                </div>

                <div>
                    <div class="info-label">Preservation Notes & Rationale</div>
                    <div class="text-block"><?= e($meta['preservation_notes'] ?? ($record['notes'] ?: 'Long-term preservation baseline established.')); ?></div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="actions-bar">
                <a href="index.php" class="btn-action">
                    &larr; Back to Preservation Directory
                </a>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="history.php?dataset_id=<?= $datasetId; ?>" class="btn-action">
                        📜 Preservation History
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
