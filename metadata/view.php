<?php
/**
 * View Dataset Metadata
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

$metadataId = (int)($_GET['id'] ?? 0);
$datasetId  = (int)($_GET['dataset_id'] ?? 0);

if ($metadataId <= 0 && $datasetId <= 0) {
    $_SESSION['metadata_error'] = 'Invalid metadata identifier.';
    header('Location: index.php');
    exit;
}

try {
    if ($metadataId > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                d.id AS dataset_id,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                d.owner_id AS dataset_owner_id,
                d.current_version,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name
            FROM dataset_metadata m
            INNER JOIN datasets d ON m.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON d.owner_id = u.id
            WHERE m.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $metadataId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                m.*,
                d.id AS dataset_id,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                d.owner_id AS dataset_owner_id,
                d.current_version,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                u.first_name AS owner_first_name,
                u.last_name AS owner_last_name
            FROM dataset_metadata m
            INNER JOIN datasets d ON m.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users u ON d.owner_id = u.id
            WHERE m.dataset_id = :dataset_id
            LIMIT 1
        ");
        $stmt->execute([':dataset_id' => $datasetId]);
    }

    $metadata = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$metadata) {
        $_SESSION['metadata_error'] = 'The requested metadata record was not found.';
        header('Location: index.php');
        exit;
    }

    $datasetId = (int)$metadata['dataset_id'];
    $projectId = (int)$metadata['project_id'];
    $datasetOwnerId = (int)$metadata['dataset_owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce view authorization
    if (!canViewDataset($projectRole, $systemRole, $metadata['dataset_access_level'], $datasetOwnerId, $userId)) {
        $_SESSION['metadata_error'] = 'Access denied: You do not have permission to view metadata for this dataset.';
        header('Location: index.php');
        exit;
    }

    $canEdit = canManageDatasetMetadata($projectRole, $systemRole, $datasetOwnerId, $userId);
    $completeness = calculateMetadataCompleteness($metadata);

} catch (PDOException $e) {
    error_log("Metadata View Query Error: " . $e->getMessage());
    $_SESSION['metadata_error'] = 'A system error occurred while retrieving metadata.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['metadata_success'] ?? null;
$errorMsg   = $_SESSION['metadata_error'] ?? null;
unset($_SESSION['metadata_success'], $_SESSION['metadata_error']);

// Keywords parser
$keywordTags = array_filter(array_map('trim', explode(',', (string)$metadata['keywords'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metadata: <?= e($metadata['dataset_title']); ?> — FUD RDM System</title>
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
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
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
        .text-block {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1rem;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            margin-top: 0.25rem;
        }
        .tag-pill {
            display: inline-block;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 0.35rem;
            margin-bottom: 0.35rem;
        }
        .badge-complete {
            background: #d1fae5;
            color: #065f46;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-mostly-complete {
            background: #fef3c7;
            color: #92400e;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-incomplete {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
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

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Metadata Directory
            </a>
            <a href="../datasets/view.php?id=<?= $datasetId; ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;">
                View Dataset File Repository &rarr;
            </a>
        </div>

        <!-- Header Card -->
        <div class="view-header">
            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($metadata['project_code']); ?></span>
                    <span style="margin-left: 0.5rem;" class="<?= $completeness['badge_class']; ?>">
                        Completeness: <?= $completeness['percentage']; ?>% (<?= $completeness['status']; ?>)
                    </span>
                </div>
                <div class="header-actions">
                    <?php if ($canEdit): ?>
                        <a href="edit.php?id=<?= (int)$metadata['id']; ?>" class="btn-header btn-header-primary">
                            ✏️ Edit Metadata
                        </a>
                    <?php endif; ?>
                    <a href="../datasets/view.php?id=<?= $datasetId; ?>" class="btn-header">
                        📊 Dataset Details
                    </a>
                </div>
            </div>

            <h1 style="font-size: 1.85rem; color: var(--primary-color); font-weight: 700; line-height: 1.25; margin-bottom: 0.75rem;">
                Descriptive Metadata: <?= e($metadata['dataset_title']); ?>
            </h1>

            <div style="font-size: 0.85rem; color: var(--text-muted); display: flex; gap: 1.5rem; flex-wrap: wrap;">
                <div><strong>Research Project:</strong> <?= e($metadata['project_title']); ?></div>
                <div><strong>Primary Creator:</strong> <?= e($metadata['creator']); ?></div>
                <div><strong>Last Updated:</strong> <?= date('M d, Y H:i', strtotime($metadata['updated_at'])); ?></div>
            </div>
        </div>

        <!-- Section 1: Identification -->
        <div class="section-card">
            <h2 class="section-heading">🏷️ Dataset Identification</h2>
            <div class="info-grid">
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Creator / Principal Investigators</div>
                    <div class="info-value"><strong><?= e($metadata['creator']); ?></strong></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Subject Area / Discipline</div>
                    <div class="info-value"><?= e($metadata['subject_area']); ?></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Geographic Coverage</div>
                    <div class="info-value"><?= e($metadata['geographic_coverage']); ?></div>
                </div>

                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Keywords & Subject Tags</div>
                    <div style="margin-top: 0.35rem;">
                        <?php foreach ($keywordTags as $tag): ?>
                            <span class="tag-pill">#<?= e($tag); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Timeline & Dates -->
        <div class="section-card">
            <h2 class="section-heading">📅 Dates & Temporal Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Creation / Collection Date</div>
                    <div class="info-value">
                        <strong><?= !empty($metadata['creation_date']) ? date('F d, Y', strtotime($metadata['creation_date'])) : '—'; ?></strong>
                    </div>
                </div>

                <div class="info-item">
                    <div class="info-label">Modification Date</div>
                    <div class="info-value">
                        <?= !empty($metadata['modification_date']) ? date('F d, Y', strtotime($metadata['modification_date'])) : '<span style="color: #94a3b8; font-style: italic;">No subsequent modification date recorded</span>'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Methodology & Technical Characteristics -->
        <div class="section-card">
            <h2 class="section-heading">🔬 Methodology & Data Format</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Primary Data Nature / Type</div>
                    <div class="info-value"><strong><?= e($metadata['data_type']); ?></strong></div>
                </div>

                <div class="info-item">
                    <div class="info-label">File Format / Encoding</div>
                    <div class="info-value"><strong><?= e($metadata['file_format']); ?></strong></div>
                </div>

                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Collection Methodology</div>
                    <div class="text-block"><?= e($metadata['collection_methodology']); ?></div>
                </div>
            </div>
        </div>

        <!-- Section 4: Publications & Funding -->
        <div class="section-card">
            <h2 class="section-heading">📚 Related Publications & Funding</h2>
            <div class="info-grid">
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Related Publication / DOI Reference</div>
                    <div class="text-block"><?= !empty($metadata['related_publication']) ? e($metadata['related_publication']) : '<span style="color: #94a3b8; font-style: italic;">None specified</span>'; ?></div>
                </div>

                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Funding Source & Grant Information</div>
                    <div class="text-block"><?= !empty($metadata['funding_source']) ? e($metadata['funding_source']) : '<span style="color: #94a3b8; font-style: italic;">None specified</span>'; ?></div>
                </div>
            </div>
        </div>

        <!-- Section 5: Licensing & Access Governance -->
        <div class="section-card">
            <h2 class="section-heading">⚖️ Licensing & Access Governance</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Data License</div>
                    <div class="info-value"><strong style="color: #065f46;"><?= e($metadata['license']); ?></strong></div>
                </div>

                <div class="info-item">
                    <div class="info-label">Repository Access Level</div>
                    <div class="info-value"><?= e(ucfirst($metadata['dataset_access_level'])); ?></div>
                </div>

                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Access Conditions & Terms of Use</div>
                    <div class="text-block"><?= e($metadata['access_conditions']); ?></div>
                </div>
            </div>
        </div>

        <!-- Section 6: Processing & Provenance -->
        <div class="section-card">
            <h2 class="section-heading">⚙️ Processing Documentation & Provenance</h2>
            <div class="info-item">
                <div class="info-label">Data Cleaning, Transformation & Transformation Pipeline</div>
                <div class="text-block" style="min-height: 100px;"><?= e($metadata['processing_documentation']); ?></div>
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
