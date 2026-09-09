<?php
/**
 * Dataset Versions History View
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
    // 1. Fetch dataset details
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.id AS project_id,
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

    // 2. Enforce view authorization
    if (!canViewDataset($projectRole, $systemRole, $dataset['access_level'], $ownerId, $userId)) {
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to view version history for this dataset.';
        header('Location: index.php');
        exit;
    }

    $canEdit     = canEditDataset($projectRole, $systemRole, $ownerId, $userId);
    $canDownload = canDownloadDataset($projectRole, $systemRole, $dataset['access_level'], $ownerId, $userId);

    // 3. Fetch all versions
    $vStmt = $pdo->prepare("
        SELECT 
            v.*,
            u.first_name AS uploader_first_name,
            u.last_name AS uploader_last_name
        FROM dataset_versions v
        INNER JOIN users u ON v.uploaded_by = u.id
        WHERE v.dataset_id = :dataset_id
        ORDER BY v.version_number DESC
    ");
    $vStmt->execute([':dataset_id' => $datasetId]);
    $versions = $vStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Dataset Versions Query Error: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'A system error occurred while loading version history.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['dataset_success'] ?? null;
$errorMsg   = $_SESSION['dataset_error'] ?? null;
unset($_SESSION['dataset_success'], $_SESSION['dataset_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Version History — <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title h1 {
            font-size: 1.75rem;
            color: var(--primary-color);
            font-weight: 700;
        }
        .version-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            position: relative;
        }
        .version-card.is-current {
            border: 2px solid #059669;
            background: #ffffff;
        }
        .version-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
        }
        .version-badge {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .current-pill {
            background: #d1fae5;
            color: #065f46;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.05em;
            margin-bottom: 0.2rem;
        }
        .info-value {
            font-size: 0.9rem;
            color: var(--text-main);
        }
        .checksum-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.5rem 0.75rem;
            font-family: monospace;
            font-size: 0.8rem;
            color: #334155;
            word-break: break-all;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
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

        <!-- Breadcrumb -->
        <div style="margin-bottom: 1rem;">
            <a href="view.php?id=<?= (int)$dataset['id']; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Dataset View
            </a>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); font-size: 0.9rem;">
                    <?= e($dataset['project_code']); ?>
                </span>
                <h1>Version History: <?= e($dataset['title']); ?></h1>
                <p>Tracking full lifecycle deposit history and cryptographic integrity</p>
            </div>
            <div>
                <?php if ($canEdit): ?>
                    <a href="upload.php?id=<?= (int)$dataset['id']; ?>" class="btn-action btn-action-primary">
                        ➕ Upload New Version
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Version History Cards -->
        <?php foreach ($versions as $v): 
            $isCurrent = ((int)$v['version_number'] === (int)$dataset['current_version']);
        ?>
            <div class="version-card <?= $isCurrent ? 'is-current' : ''; ?>">
                <div class="version-header">
                    <div class="version-badge">
                        <span>Version <?= (int)$v['version_number']; ?></span>
                        <?php if ($isCurrent): ?>
                            <span class="current-pill">Active / Current</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($canDownload): ?>
                            <a href="download.php?id=<?= (int)$dataset['id']; ?>&version=<?= (int)$v['version_number']; ?>" class="btn-action btn-action-success">
                                ⬇️ Download v<?= (int)$v['version_number']; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Original Filename</div>
                        <div class="info-value"><strong>📄 <?= e($v['file_name']); ?></strong></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">File Size</div>
                        <div class="info-value"><?= formatFileSize((int)$v['file_size']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">MIME Type</div>
                        <div class="info-value"><?= e($v['mime_type'] ?: 'application/octet-stream'); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Uploaded By</div>
                        <div class="info-value"><?= e($v['uploader_first_name'] . ' ' . $v['uploader_last_name']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Upload Timestamp</div>
                        <div class="info-value"><?= date('M d, Y H:i:s', strtotime($v['created_at'])); ?></div>
                    </div>
                </div>

                <div style="margin-bottom: 0.75rem;">
                    <div class="info-label">SHA-256 Integrity Checksum</div>
                    <div class="checksum-box"><?= e($v['checksum']); ?></div>
                </div>

                <?php if (!empty($v['version_notes'])): ?>
                    <div>
                        <div class="info-label">Version Notes / Changelog</div>
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; font-size: 0.875rem; color: var(--text-main); margin-top: 0.25rem;">
                            <?= e($v['version_notes']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

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
