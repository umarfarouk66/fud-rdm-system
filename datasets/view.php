<?php
/**
 * Detailed Dataset View
 * RDM Information System - Step 7, 8, 9 & 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';
require_once __DIR__ . '/citation_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';

// Allow public viewing of discoverable datasets (public & restricted)
$isGuest = !isLoggedIn();
$user = $isGuest ? null : currentUser();
$userId = $user ? (int)$user['id'] : 0;
$systemRole = $user ? $user['role'] : 'guest';

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
            p.department AS project_department,
            p.faculty AS project_faculty,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $dataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset) {
        $_SESSION['dataset_error'] = 'The requested research dataset was not found.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    $accessLevel = strtolower(trim($dataset['access_level']));

    // 2. Enforce view authorization
    if (!canViewDataset($projectRole, $systemRole, $dataset['access_level'], $ownerId, $userId)) {
        if ($isGuest) {
            $_SESSION['auth_error'] = 'Login is required to view private research datasets.';
            header('Location: ../public/login.php');
            exit;
        }
        $_SESSION['dataset_error'] = 'Access denied: You do not have permission to view this dataset.';
        header('Location: index.php');
        exit;
    }

    // 3. Increment view count and log access
    $pdo->prepare("UPDATE datasets SET view_count = view_count + 1 WHERE id = :id")->execute([':id' => $datasetId]);
    log_dataset_access($pdo, $datasetId, $userId, 'view');
    $dataset['view_count']++;

    // 4. Fetch current active version details
    $versionStmt = $pdo->prepare("
        SELECT 
            v.*,
            u.first_name AS uploader_first_name,
            u.last_name AS uploader_last_name
        FROM dataset_versions v
        INNER JOIN users u ON v.uploaded_by = u.id
        WHERE v.dataset_id = :dataset_id AND v.version_number = :version_number
        LIMIT 1
    ");
    $versionStmt->execute([
        ':dataset_id'      => $datasetId,
        ':version_number'  => $dataset['current_version']
    ]);
    $currentVersion = $versionStmt->fetch(PDO::FETCH_ASSOC);

    // 5. Fetch associated Metadata (Step 8)
    $metaStmt = $pdo->prepare("SELECT * FROM dataset_metadata WHERE dataset_id = :dataset_id LIMIT 1");
    $metaStmt->execute([':dataset_id' => $datasetId]);
    $datasetMetadata = $metaStmt->fetch(PDO::FETCH_ASSOC);

    $metaCompleteness = $datasetMetadata ? calculateMetadataCompleteness($datasetMetadata) : null;

    // 6. Check permissions and Access Requests (Step 9)
    $canEdit     = canEditDataset($projectRole, $systemRole, $ownerId, $userId);
    $canDownload = canDownloadDataset($projectRole, $systemRole, $dataset['access_level'], $ownerId, $userId, $pdo, $datasetId);
    $canDelete   = canDeleteDataset($projectRole, $systemRole, $ownerId, $userId);
    $canManageRequests = canManageAccessRequests($projectRole, $systemRole, $ownerId, $userId);
    $isLibrarian = canManagePreservation($systemRole);

    // Fetch user access request status for restricted datasets
    $userAccessRequest = null;
    if ($accessLevel === 'restricted' && !$canEdit && $projectRole === null) {
        $userAccessRequest = getUserLatestAccessRequest($pdo, $datasetId, $userId);
    }

    // Fetch count of pending requests for this dataset (for managers/owners)
    $pendingRequestsCount = 0;
    if ($canManageRequests) {
        $pCountStmt = $pdo->prepare("SELECT COUNT(*) FROM access_requests WHERE dataset_id = :id AND status = 'pending'");
        $pCountStmt->execute([':id' => $datasetId]);
        $pendingRequestsCount = (int)$pCountStmt->fetchColumn();
    }

    // 7. Fetch Latest Preservation Record (Step 10)
    $presStmt = $pdo->prepare("
        SELECT 
            pr.*,
            u.first_name AS librarian_first_name,
            u.last_name AS librarian_last_name
        FROM preservation_records pr
        INNER JOIN users u ON pr.performed_by = u.id
        WHERE pr.dataset_id = :id
        ORDER BY pr.performed_at DESC, pr.id DESC
        LIMIT 1
    ");
    $presStmt->execute([':id' => $datasetId]);
    $latestPreservation = $presStmt->fetch(PDO::FETCH_ASSOC);
    $presNotes = $latestPreservation ? parsePreservationNotes($latestPreservation['notes']) : [];

    // 8. Fetch Persistent Identifiers & Academic Citations
    $datasetIdentifiers = getDatasetIdentifiers($pdo, $datasetId);
    $datasetCitations   = getOrGenerateDatasetCitations($pdo, $datasetId, $dataset, $datasetMetadata);

} catch (PDOException $e) {
    error_log("Dataset View Query Error: " . $e->getMessage());
    $_SESSION['dataset_error'] = 'A system error occurred while loading the dataset.';
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
    <title><?= e($dataset['title']); ?> — Dataset View — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .view-header {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .project-code-tag {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
            display: inline-block;
        }
        .header-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .btn-header {
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
        .btn-header:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background-color: var(--primary-light);
        }
        .btn-header-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-header-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-header-success {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .btn-header-success:hover {
            background-color: #047857;
            color: #ffffff;
        }
        .btn-header-warning {
            background-color: #d97706;
            color: #ffffff;
            border-color: #d97706;
        }
        .btn-header-warning:hover {
            background-color: #b45309;
            color: #ffffff;
        }
        .btn-header-danger {
            color: #dc2626;
            background-color: #fef2f2;
            border-color: #fecaca;
        }
        .btn-header-danger:hover {
            background-color: #dc2626;
            color: #ffffff;
        }
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            box-shadow: var(--shadow-sm);
        }
        .section-heading {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.6rem;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
        }
        .info-item {
            margin-bottom: 0.75rem;
        }
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        .info-value {
            font-size: 0.95rem;
            color: var(--text-main);
            line-height: 1.5;
        }
        .checksum-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.6rem 0.85rem;
            font-family: monospace;
            font-size: 0.85rem;
            color: #334155;
            word-break: break-all;
            margin-top: 0.25rem;
        }
        .version-hero-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-md);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .restricted-lock-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: var(--radius-md);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .metadata-banner {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-top: 0.5rem;
        }
        .tag-pill {
            display: inline-block;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 0.25rem;
        }
        .badge-complete { background: #d1fae5; color: #065f46; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
        .badge-mostly-complete { background: #fef3c7; color: #92400e; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
        .badge-incomplete { background: #fee2e2; color: #991b1b; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; display: inline-block; }
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
            <?php if (!$isGuest && $user): ?>
                <div class="user-badge-container">
                    <div>
                        <div class="user-name"><?= e($user['name']); ?></div>
                        <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                    </div>
                    <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
                </div>
                <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
            <?php else: ?>
                <a href="../public/index.php" class="btn-logout" style="background: transparent; color: var(--primary-color);">Home</a>
                <a href="../public/login.php" class="btn-logout" style="background: var(--primary-color); color: #ffffff; margin-right: 0.35rem;">Sign In</a>
                <a href="../public/register.php" class="btn-logout" style="background: var(--accent-color); color: #ffffff;">Register</a>
            <?php endif; ?>
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

        <!-- Pending Request Notification for Custodians -->
        <?php if ($pendingRequestsCount > 0): ?>
            <div style="background: #eff6ff; border: 1px solid #93c5fd; border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <div style="color: #1e40af; font-size: 0.95rem;">
                    📬 <strong><?= $pendingRequestsCount; ?> Pending Access Request(s)</strong> awaiting your compliance review.
                </div>
                <a href="../access/index.php" class="btn-header btn-header-primary" style="padding: 0.4rem 0.85rem; font-size: 0.85rem;">
                    ⚖️ Review Requests
                </a>
            </div>
        <?php endif; ?>

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Datasets Repository
            </a>
            <a href="../projects/view.php?id=<?= $projectId; ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;">
                View Project Workspace &rarr;
            </a>
        </div>

        <!-- Header Overview Card -->
        <div class="view-header">
            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($dataset['project_code']); ?></span>
                    <span style="margin-left: 0.5rem; text-transform: uppercase; font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 9999px; background: <?= $accessLevel === 'public' ? '#d1fae5; color: #065f46;' : ($accessLevel === 'restricted' ? '#fef3c7; color: #92400e;' : '#f1f5f9; color: #475569;'); ?>">
                        <?= e($dataset['access_level']); ?> Access
                    </span>
                    <span style="margin-left: 0.35rem;">
                        <?= getPreservationStatusBadge($dataset['status']); ?>
                    </span>
                </div>
                <div class="header-actions">
                    <?php if ($canDownload): ?>
                        <a href="download.php?id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-success">
                            ⬇️ Download (v<?= (int)$dataset['current_version']; ?>)
                        </a>
                    <?php elseif ($accessLevel === 'restricted' && !$canEdit && $projectRole === null): ?>
                        <?php if (!$userAccessRequest): ?>
                            <a href="../access/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-warning">
                                🔑 Request Access
                            </a>
                        <?php elseif ($userAccessRequest['status'] === 'pending'): ?>
                            <a href="../access/view.php?id=<?= (int)$userAccessRequest['id']; ?>" class="btn-header" style="background: #fef3c7; color: #92400e; border-color: #fde68a;">
                                ⏳ Request Pending
                            </a>
                        <?php elseif ($userAccessRequest['status'] === 'rejected'): ?>
                            <a href="../access/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-warning">
                                🔄 Re-request Access
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <a href="versions.php?id=<?= (int)$dataset['id']; ?>" class="btn-header">
                        📑 Version History (<?= (int)$dataset['current_version']; ?>)
                    </a>

                    <?php if ($canEdit && $dataset['status'] !== 'archived'): ?>
                        <a href="upload.php?id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-primary">
                            ➕ Upload New Version
                        </a>
                        <a href="edit.php?id=<?= (int)$dataset['id']; ?>" class="btn-header">
                            ✏️ Edit Dataset
                        </a>
                    <?php endif; ?>

                    <?php if ($canDelete && !in_array($dataset['status'], ['preserved', 'archived'])): ?>
                        <form action="delete.php" method="POST" style="display: inline;" onsubmit="return confirm('WARNING: Are you sure you want to delete this dataset? All version files and metadata will be permanently removed.');">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="dataset_id" value="<?= (int)$dataset['id']; ?>">
                            <button type="submit" class="btn-header btn-header-danger">
                                🗑️ Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <h1 style="font-size: 1.85rem; color: var(--primary-color); font-weight: 700; line-height: 1.25; margin-bottom: 0.75rem;">
                <?= e($dataset['title']); ?>
            </h1>

            <p style="font-size: 0.95rem; color: var(--text-main); margin-bottom: 1.25rem; line-height: 1.6;">
                <?= e($dataset['description']); ?>
            </p>

            <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 1.5rem; flex-wrap: wrap;">
                <div><strong>Depositor:</strong> <?= e($dataset['owner_first_name'] . ' ' . $dataset['owner_last_name']); ?></div>
                <div><strong>Research Project:</strong> <?= e($dataset['project_title']); ?></div>
                <div><strong>Total Storage:</strong> <?= formatFileSize((int)$dataset['total_size']); ?></div>
                <div><strong>Downloads:</strong> <?= (int)$dataset['download_count']; ?></div>
                <div><strong>Views:</strong> <?= (int)$dataset['view_count']; ?></div>
            </div>
        </div>

        <!-- Section: Preservation & Archival Governance (Step 10) -->
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                <h2 class="section-heading" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    🏛️ Long-Term Preservation & Archival Status
                </h2>
                <div style="display: flex; gap: 0.5rem;">
                    <?php if ($latestPreservation): ?>
                        <a href="../preservation/history.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                            📜 Preservation History
                        </a>
                        <?php if ($isLibrarian): ?>
                            <a href="../preservation/verify.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-success" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                                🔍 Re-verify Integrity
                            </a>
                            <?php if ($dataset['status'] === 'preserved'): ?>
                                <a href="../preservation/archive.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-warning" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;" onclick="return confirm('Confirm: Move this preserved dataset to the Long-Term Archive?');">
                                    📦 Archive
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php elseif ($isLibrarian): ?>
                        <a href="../preservation/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-primary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                            🛡️ Execute Preservation
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($latestPreservation): ?>
                <div class="metadata-banner">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Preservation State</div>
                            <div class="info-value"><?= getPreservationStatusBadge($dataset['status']); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Storage Location Tier</div>
                            <div class="info-value"><strong style="color: #1e40af;"><?= e($presNotes['location'] ?? 'Institutional Repository'); ?></strong></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Preserved / Archived Date</div>
                            <div class="info-value"><?= date('M d, Y H:i', strtotime($latestPreservation['performed_at'])); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Preserving Librarian</div>
                            <div class="info-value"><?= e($latestPreservation['librarian_first_name'] . ' ' . $latestPreservation['librarian_last_name']); ?></div>
                        </div>

                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Preservation & Provenance Notes</div>
                            <div style="font-size: 0.9rem; color: #334155; margin-top: 0.2rem;">
                                <?= e($presNotes['preservation_notes'] ?? $latestPreservation['notes']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="metadata-banner" style="text-align: center; padding: 1.5rem;">
                    <div style="font-size: 0.95rem; color: var(--text-main); font-weight: 600; margin-bottom: 0.3rem;">
                        This dataset is currently awaiting institutional long-term preservation.
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                        Preservation locks the cryptographic baseline SHA-256 hash and guarantees immutable storage replication.
                    </div>
                    <?php if ($isLibrarian): ?>
                        <a href="../preservation/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-primary">
                            🛡️ Execute Preservation Now &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Access Governance Notice for Restricted Datasets (Step 9) -->
        <?php if ($accessLevel === 'restricted' && !$canEdit && $projectRole === null): ?>
            <?php if ($canDownload): ?>
                <div class="version-hero-card" style="background: #f0fdf4; border-color: #bbf7d0; margin-bottom: 1.75rem;">
                    <div>
                        <div style="font-weight: 700; color: #065f46; font-size: 1.05rem;">
                            ✅ Access Approved
                        </div>
                        <div style="font-size: 0.875rem; color: #047857; margin-top: 0.25rem;">
                            You have an active approved access request for this restricted dataset.
                        </div>
                    </div>
                    <a href="download.php?id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-success" style="padding: 0.65rem 1.25rem;">
                        ⬇️ Download Dataset File
                    </a>
                </div>
            <?php elseif ($userAccessRequest && $userAccessRequest['status'] === 'pending'): ?>
                <div class="restricted-lock-banner">
                    <div>
                        <div style="font-weight: 700; color: #92400e; font-size: 1.05rem;">
                            ⏳ Access Request Pending Review
                        </div>
                        <div style="font-size: 0.875rem; color: #b45309; margin-top: 0.25rem;">
                            Your request submitted on <?= date('M d, Y', strtotime($userAccessRequest['created_at'])); ?> is currently being evaluated by the custodian.
                        </div>
                    </div>
                    <a href="../access/view.php?id=<?= (int)$userAccessRequest['id']; ?>" class="btn-header" style="background: #ffffff; color: #92400e; border-color: #fde68a;">
                        👁️ View Request Status
                    </a>
                </div>
            <?php elseif ($userAccessRequest && $userAccessRequest['status'] === 'rejected'): ?>
                <div class="restricted-lock-banner" style="background: #fef2f2; border-color: #fecaca;">
                    <div>
                        <div style="font-weight: 700; color: #991b1b; font-size: 1.05rem;">
                            ❌ Previous Access Request Declined
                        </div>
                        <div style="font-size: 0.875rem; color: #b91c1c; margin-top: 0.25rem;">
                            <?= e($userAccessRequest['reviewer_comment'] ?: 'Request was not approved.'); ?> You may submit an updated request with detailed rationale.
                        </div>
                    </div>
                    <a href="../access/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-warning">
                        🔄 Re-apply for Access
                    </a>
                </div>
            <?php else: ?>
                <div class="restricted-lock-banner">
                    <div>
                        <div style="font-weight: 700; color: #92400e; font-size: 1.05rem;">
                            🔒 Restricted Research Dataset
                        </div>
                        <div style="font-size: 0.875rem; color: #b45309; margin-top: 0.25rem;">
                            Access to raw data files requires prior authorization from the dataset custodian.
                        </div>
                    </div>
                    <a href="../access/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-warning">
                        🔑 Request Access &rarr;
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Section: Descriptive Metadata (Step 8) -->
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-heading" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    🏷️ Dataset Descriptive Metadata
                </h2>
                <?php if ($datasetMetadata): ?>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span class="<?= $metaCompleteness['badge_class']; ?>">
                            <?= $metaCompleteness['percentage']; ?>% <?= $metaCompleteness['status']; ?>
                        </span>
                        <a href="../metadata/view.php?id=<?= (int)$datasetMetadata['id']; ?>" class="btn-header" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                            👁️ Full Metadata
                        </a>
                        <?php if ($canEdit && $dataset['status'] !== 'archived'): ?>
                            <a href="../metadata/edit.php?id=<?= (int)$datasetMetadata['id']; ?>" class="btn-header btn-header-primary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                                ✏️ Edit Metadata
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$datasetMetadata): ?>
                <div class="metadata-banner" style="text-align: center; padding: 1.75rem;">
                    <h3 style="font-size: 1.05rem; color: var(--primary-color); margin-bottom: 0.35rem;">
                        No metadata has been added for this dataset yet.
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 1rem;">
                        Documenting descriptive metadata makes research data discoverable, citable and compliant with FAIR data principles.
                    </p>
                    <?php if ($canEdit): ?>
                        <a href="../metadata/create.php?dataset_id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-primary">
                            ➕ Create Metadata Record
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="metadata-banner">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Creator / Primary Investigator</div>
                            <div class="info-value"><strong><?= e($datasetMetadata['creator']); ?></strong></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Subject Domain</div>
                            <div class="info-value"><?= e($datasetMetadata['subject_area']); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Data License</div>
                            <div class="info-value" style="color: #065f46; font-weight: 600;"><?= e($datasetMetadata['license']); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">Creation Date</div>
                            <div class="info-value"><?= date('M d, Y', strtotime($datasetMetadata['creation_date'])); ?></div>
                        </div>

                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Keywords</div>
                            <div style="margin-top: 0.25rem;">
                                <?php foreach (array_filter(array_map('trim', explode(',', (string)$datasetMetadata['keywords']))) as $kw): ?>
                                    <span class="tag-pill">#<?= e($kw); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Current Version Card -->
        <?php if ($currentVersion): ?>
            <div class="section-card">
                <h2 class="section-heading">📦 Current Active Version Details (Version <?= (int)$currentVersion['version_number']; ?>)</h2>

                <div class="version-hero-card">
                    <div>
                        <div style="font-size: 1.15rem; font-weight: 700; color: #065f46; margin-bottom: 0.25rem;">
                            📄 <?= e($currentVersion['file_name']); ?>
                        </div>
                        <div style="font-size: 0.85rem; color: #047857;">
                            Size: <strong><?= formatFileSize((int)$currentVersion['file_size']); ?></strong> &bull; 
                            MIME: <strong><?= e($currentVersion['mime_type']); ?></strong> &bull; 
                            Uploaded: <strong><?= date('M d, Y H:i', strtotime($currentVersion['created_at'])); ?></strong> by <?= e($currentVersion['uploader_first_name'] . ' ' . $currentVersion['uploader_last_name']); ?>
                        </div>
                    </div>
                    <?php if ($canDownload): ?>
                        <div>
                            <a href="download.php?id=<?= (int)$dataset['id']; ?>&version=<?= (int)$currentVersion['version_number']; ?>" class="btn-header btn-header-success" style="padding: 0.65rem 1.25rem;">
                                ⬇️ Download File
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="info-grid">
                    <div class="info-item" style="grid-column: 1 / -1;">
                        <div class="info-label">SHA-256 Cryptographic Checksum (Integrity Verification)</div>
                        <div class="checksum-box"><?= e($currentVersion['checksum']); ?></div>
                    </div>

                    <?php if (!empty($currentVersion['version_notes'])): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="info-label">Version Notes</div>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem 1rem; font-size: 0.9rem;">
                                <?= e($currentVersion['version_notes']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Persistent Identifiers & Academic Citation Box (Step 16) -->
        <div class="section-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                <h2 class="section-heading" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">
                    📜 Cite Dataset & Persistent Identifiers
                </h2>
                <?php if ($canEdit || in_array($systemRole, ['librarian', 'admin'], true)): ?>
                    <a href="identifiers.php?id=<?= (int)$dataset['id']; ?>" class="btn-header btn-header-primary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                        ⚙️ Manage Identifiers
                    </a>
                <?php endif; ?>
            </div>

            <!-- Identifiers Badges -->
            <div style="margin-bottom: 1.25rem;">
                <strong>Persistent Identifiers:</strong>
                <?php if (!empty($datasetIdentifiers)): ?>
                    <?php foreach ($datasetIdentifiers as $idRow): ?>
                        <span style="display: inline-block; margin-left: 0.5rem; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 0.25rem 0.65rem; border-radius: var(--radius-sm); font-size: 0.85rem; font-family: monospace;">
                            <strong><?= e($idRow['identifier_type']); ?>:</strong> <?= e($idRow['identifier_value']); ?>
                        </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span style="color: var(--text-muted); font-size: 0.875rem; margin-left: 0.5rem;">
                        Institutional ID: <code>RDM-PID-<?= str_pad((string)$dataset['id'], 6, '0', STR_PAD_LEFT); ?></code>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Citation Generator Selector -->
            <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                    <label for="citation-style-select" style="font-weight: 700; font-size: 0.9rem; color: var(--text-main);">
                        Referencing Style:
                    </label>
                    <select id="citation-style-select" class="form-control" style="width: auto; padding: 0.3rem 0.75rem; font-size: 0.85rem;" onchange="updateCitationDisplay()">
                        <option value="apa">APA (7th Edition)</option>
                        <option value="bibtex">BibTeX Format</option>
                        <option value="chicago">Chicago (17th Edition)</option>
                        <option value="mla">MLA (9th Edition)</option>
                        <option value="harvard">Harvard</option>
                        <option value="ieee">IEEE</option>
                    </select>
                </div>

                <div id="citation-text-box" style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; font-family: monospace; font-size: 0.875rem; white-space: pre-wrap; word-break: break-word; line-height: 1.5; color: #1e293b;">
                    <?= e($datasetCitations['apa']['text'] ?? ''); ?>
                </div>

                <div style="display: flex; gap: 0.75rem; margin-top: 0.75rem; justify-content: flex-end;">
                    <button type="button" class="btn-header" onclick="copyCitationText()">
                        📋 Copy Citation
                    </button>
                </div>
            </div>

            <script>
                const citationsData = <?= json_encode($datasetCitations); ?>;
                function updateCitationDisplay() {
                    const style = document.getElementById('citation-style-select').value;
                    if (citationsData[style]) {
                        document.getElementById('citation-text-box').textContent = citationsData[style].text;
                    }
                }
                function copyCitationText() {
                    const text = document.getElementById('citation-text-box').textContent;
                    navigator.clipboard.writeText(text).then(() => {
                        alert('Citation copied to clipboard!');
                    }).catch(err => {
                        console.error('Failed to copy: ', err);
                    });
                }
            </script>
        </div>

        <!-- Governance & Dataset Attributes -->
        <div class="section-card">
            <h2 class="section-heading">🏷️ Governance & Dataset Attributes</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Dataset Nature / Type</div>
                    <div class="info-value"><strong><?= e($dataset['dataset_type'] ?: 'General Data'); ?></strong></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Repository Access Level</div>
                    <div class="info-value"><?= e(ucfirst($dataset['access_level'])); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Current Lifecycle Status</div>
                    <div class="info-value"><?= e(ucfirst($dataset['status'])); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Deposited Date</div>
                    <div class="info-value"><?= date('M d, Y H:i', strtotime($dataset['created_at'])); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Last Modified Date</div>
                    <div class="info-value"><?= date('M d, Y H:i', strtotime($dataset['updated_at'])); ?></div>
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
