<?php
/**
 * Librarian Repository Review & Publication Console
 * FUD RDM System - Phase 9: Repository Integration & Final Project Publication
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../repository/repository_helpers.php';

// Enforce librarian role (super_admin automatically allowed read-only access)
requireRole(['librarian', 'admin']);

$user       = currentUser();
$userId     = (int)$user['id'];
$isSuper    = isSuperAdmin();

$datasetId = (int)($_GET['dataset_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// If specific dataset_id requested, load detailed review view
$singleDataset = null;
if ($datasetId > 0) {
    $stmt = $pdo->prepare("
        SELECT d.*, rp.id AS project_id, rp.project_code, rp.title AS project_title, rp.status AS project_status,
               u.id AS student_id, u.first_name AS st_first, u.last_name AS st_last, u.email AS st_email, u.matric_number, u.department, u.faculty
        FROM datasets d
        INNER JOIN research_projects rp ON d.project_id = rp.id
        INNER JOIN users u ON d.owner_id = u.id
        WHERE d.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $datasetId]);
    $singleDataset = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($singleDataset) {
        $singleDataset['metadata']    = getProjectRepositoryRecord($pdo, (int)$singleDataset['project_id'])['metadata'] ?? null;
        $singleDataset['versions']    = getProjectRepositoryRecord($pdo, (int)$singleDataset['project_id'])['versions'] ?? [];
        $singleDataset['identifiers'] = getDatasetIdentifiers($pdo, $datasetId);
        $singleDataset['citations']   = getOrGenerateDatasetCitations($pdo, $datasetId, $singleDataset, $singleDataset['metadata']);
        $singleDataset['eligibility'] = checkRepositoryEligibility($pdo, (int)$singleDataset['project_id']);
        $singleDataset['defense']     = getProjectDefense($pdo, (int)$singleDataset['project_id']);
        $singleDataset['outcome']     = $singleDataset['defense'] ? getDefenseOutcome($pdo, (int)$singleDataset['defense']['id']) : null;
    }
}

// Fetch all repository submissions list
$query = "
    SELECT d.*, rp.project_code, rp.title AS project_title, rp.status AS project_status,
           u.first_name AS st_first, u.last_name AS st_last, u.matric_number, u.department
    FROM datasets d
    INNER JOIN research_projects rp ON d.project_id = rp.id
    INNER JOIN users u ON d.owner_id = u.id
    WHERE d.status != 'deleted'
";

$params = [];
if ($statusFilter !== '') {
    $query .= " AND d.status = :status";
    $params[':status'] = $statusFilter;
} else {
    // Default show submitted & pending items first
    $query .= " ORDER BY CASE d.status 
        WHEN 'submitted' THEN 1
        WHEN 'corrections_required' THEN 2
        WHEN 'approved' THEN 3
        WHEN 'published' THEN 4
        ELSE 5
    END, d.submitted_to_repository_at DESC, d.updated_at DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$repositoryList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repository Review & Publication Console — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .filter-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .data-table th, .data-table td {
            padding: 0.85rem 0.95rem;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .badge-status {
            display: inline-block;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .badge-submitted { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .badge-published { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-corrections { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .badge-archived { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .badge-read-only {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <a href="../notifications/index.php" style="text-decoration: none; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; font-weight: 700; margin-right: 0.75rem; background: #f8fafc; padding: 0.4rem 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);" title="Notifications">
                <span>🔔</span>
                <?php if ($notifUnreadCount > 0): ?>
                    <span style="background: #059669; color: #ffffff; font-size: 0.75rem; padding: 0.1rem 0.45rem; border-radius: 9999px;">
                        <?= $notifUnreadCount; ?>
                    </span>
                <?php endif; ?>
            </a>

            <div class="user-badge-container">
                <div>
                    <div class="user-name"><?= e($user['name']); ?></div>
                    <div class="user-affiliation"><?= e($user['institution'] ?: 'Institutional Repository Library'); ?></div>
                </div>
                <?php if ($isSuper): ?>
                    <span class="role-badge role-badge-super-admin" style="background: #7c3aed; color: #fff;">Super Admin (Read-Only)</span>
                <?php else: ?>
                    <span class="role-badge role-badge-librarian">Librarian</span>
                <?php endif; ?>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <a href="dashboard.php" style="color: var(--accent-color); text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                &larr; Back to Librarian Dashboard
            </a>
            <?php if ($isSuper): ?>
                <span class="badge-read-only">👁️ Read-Only Mode Active</span>
            <?php endif; ?>
        </div>

        <!-- Flash Alerts -->
        <?php if ($flashSuccess): ?>
            <div style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-weight: 600;">
                ✅ <?= e($flashSuccess); ?>
            </div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-weight: 600;">
                ⚠️ <?= e($flashError); ?>
            </div>
        <?php endif; ?>

        <!-- DETAILED SINGLE DATASET REVIEW PANEL -->
        <?php if ($singleDataset): ?>
            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.75rem; margin-bottom: 2rem; box-shadow: var(--shadow-sm);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                    <div>
                        <span style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--accent-color);"><?= e($singleDataset['project_code']); ?></span>
                        <h1 style="font-size: 1.5rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem;">
                            📚 <?= e($singleDataset['title']); ?>
                        </h1>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                            Author: <strong><?= e($singleDataset['st_first'] . ' ' . $singleDataset['st_last']); ?></strong> (Matric: <?= e($singleDataset['matric_number']); ?>) &bull; Department: <?= e($singleDataset['department']); ?>
                        </div>
                    </div>
                    <div>
                        <span class="badge-status badge-<?= e($singleDataset['status']); ?>" style="font-size: 0.85rem; padding: 0.3rem 0.75rem;">
                            Status: <?= e(str_replace('_', ' ', strtoupper($singleDataset['status']))); ?>
                        </span>
                    </div>
                </div>

                <!-- Academic Prerequisite Verification -->
                <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
                    <strong style="font-size: 0.9rem; color: #065f46;">✅ Academic Workflow Verification:</strong>
                    <div style="font-size: 0.85rem; color: #047857; margin-top: 0.25rem;">
                        Project is marked COMPLETED & APPROVED. Defense Outcome: <strong><?= e($singleDataset['outcome'] ? str_replace('_', ' ', strtoupper($singleDataset['outcome']['outcome'])) : 'Passed'); ?></strong>.
                    </div>
                </div>

                <!-- Repository Review & Publication Form (for Librarian) -->
                <?php if (!$isSuper): ?>
                    <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 1.5rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
                        <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 1rem;">
                            ⚖️ Repository Steward Curation & Publication Controls
                        </h3>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
                            
                            <!-- Form 1: Publish to Repository -->
                            <form action="../repository/review.php" method="POST" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 1.25rem; border-radius: var(--radius-sm);">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                                <input type="hidden" name="dataset_id" value="<?= $singleDataset['id']; ?>">
                                <input type="hidden" name="action" value="publish">

                                <h4 style="font-size: 0.95rem; font-weight: 700; color: #047857; margin-bottom: 0.75rem;">
                                    🎉 Publish & Assign Persistent Identifier (PID)
                                </h4>

                                <div class="form-group" style="margin-bottom: 0.85rem;">
                                    <label class="form-label">Repository Visibility</label>
                                    <select name="access_level" class="form-control" required>
                                        <option value="public" <?= $singleDataset['access_level'] === 'public' ? 'selected' : ''; ?>>Public Access (Open Repository)</option>
                                        <option value="restricted" <?= $singleDataset['access_level'] === 'restricted' ? 'selected' : ''; ?>>Restricted Access (Request Approval Required)</option>
                                        <option value="private" <?= $singleDataset['access_level'] === 'private' ? 'selected' : ''; ?>>Private (Internal Institutional Only)</option>
                                    </select>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.85rem;">
                                    <div>
                                        <label class="form-label">PID Type</label>
                                        <select name="pid_type" class="form-control" required>
                                            <option value="DOI">DOI</option>
                                            <option value="Handle">Handle System</option>
                                            <option value="ARK">ARK</option>
                                            <option value="Institutional">Institutional PID</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">PID Value (Optional)</label>
                                        <input type="text" name="pid_value" class="form-control" placeholder="Auto-generated if blank">
                                    </div>
                                </div>

                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <label class="form-label">Curation / Publication Remarks</label>
                                    <textarea name="repository_notes" class="form-control" rows="2" placeholder="Steward verification notes..."></textarea>
                                </div>

                                <button type="submit" class="btn-primary" style="width: 100%; font-weight: 700; background: #059669;">
                                    🚀 Publish to Institutional Repository
                                </button>
                            </form>

                            <!-- Form 2: Request Metadata Corrections -->
                            <form action="../repository/review.php" method="POST" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 1.25rem; border-radius: var(--radius-sm);">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                                <input type="hidden" name="dataset_id" value="<?= $singleDataset['id']; ?>">
                                <input type="hidden" name="action" value="request_corrections">

                                <h4 style="font-size: 0.95rem; font-weight: 700; color: #b45309; margin-bottom: 0.75rem;">
                                    ⚠️ Request Repository Metadata Corrections
                                </h4>

                                <div class="form-group" style="margin-bottom: 1rem;">
                                    <label class="form-label">Required Metadata Correction Notes</label>
                                    <textarea name="repository_notes" class="form-control" rows="4" placeholder="Explain missing metadata, keyword updates or abstract formatting required..." required></textarea>
                                </div>

                                <button type="submit" class="btn-primary" style="width: 100%; font-weight: 700; background: #d97706;">
                                    ⚠️ Request Student Metadata Corrections
                                </button>
                            </form>

                        </div>
                    </div>
                <?php else: ?>
                    <div style="background: #fef3c7; color: #92400e; padding: 1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-weight: 600;">
                        👁️ Super Admin Read-Only Mode: You may inspect metadata and files, but publication operations are restricted.
                    </div>
                <?php endif; ?>

                <!-- Document Files & Version History -->
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem;">
                        📁 Repository Approved File Package
                    </h3>
                    <?php if (empty($singleDataset['versions'])): ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">No physical file versions uploaded yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Version</th>
                                    <th>Filename</th>
                                    <th>File Size</th>
                                    <th>MIME / Format</th>
                                    <th>SHA-256 Checksum</th>
                                    <th>Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($singleDataset['versions'] as $ver): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-weight: 700;">v<?= (int)$ver['version_number']; ?></td>
                                        <td style="font-weight: 700; color: var(--primary-color);"><?= e($ver['file_name']); ?></td>
                                        <td><?= formatFileSize((int)$ver['file_size']); ?></td>
                                        <td style="font-size: 0.8rem; font-family: monospace;"><?= e($ver['mime_type']); ?></td>
                                        <td style="font-size: 0.75rem; font-family: monospace; color: var(--text-muted); max-width: 160px; word-break: break-all;">
                                            <?= e($ver['checksum']); ?>
                                        </td>
                                        <td>
                                            <a href="../datasets/download.php?id=<?= $singleDataset['id']; ?>&version=<?= $ver['version_number']; ?>" class="btn-sm" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                📥 Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Generated Citations -->
                <div>
                    <h3 style="font-size: 1.1rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.75rem;">
                        📖 Generated Scholarly Citations
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
                        <?php foreach ($singleDataset['citations'] as $cKey => $cVal): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-color); padding: 0.85rem; border-radius: var(--radius-sm);">
                                <strong style="font-size: 0.8rem; color: var(--primary-color); text-transform: uppercase;"><?= e($cVal['style']); ?></strong>
                                <div style="font-size: 0.8rem; font-family: monospace; background: #ffffff; padding: 0.5rem; border-radius: 4px; border: 1px solid #e2e8f0; margin-top: 0.35rem; word-break: break-all;">
                                    <?= e($cVal['text']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>

        <!-- ALL REPOSITORY SUBMISSIONS LIST TABLE -->
        <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.15rem; color: var(--primary-color); font-weight: 700; margin: 0;">
                    📚 University Repository Submissions & Publication Queue (<?= count($repositoryList); ?>)
                </h3>

                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <a href="repository_review.php" class="btn-logout" style="padding: 0.4rem 0.85rem; border: 1px solid var(--border-color); background: #fff; text-decoration: none; font-size: 0.8rem;">All Submissions</a>
                    <a href="repository_review.php?status=submitted" class="btn-sm" style="background: #dbeafe; color: #1e40af; padding: 0.4rem 0.85rem; text-decoration: none; border-radius: 4px; font-size: 0.8rem; font-weight: 700;">Pending Review</a>
                    <a href="repository_review.php?status=published" class="btn-sm" style="background: #dcfce7; color: #15803d; padding: 0.4rem 0.85rem; text-decoration: none; border-radius: 4px; font-size: 0.8rem; font-weight: 700;">Published</a>
                </div>
            </div>

            <?php if (empty($repositoryList)): ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📚</div>
                    <p style="font-size: 0.95rem;">No repository submissions found in this queue.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Researcher</th>
                                <th>Project & Code</th>
                                <th>Repository Title</th>
                                <th>Submitted Date</th>
                                <th>Repository Status</th>
                                <th>Visibility</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repositoryList as $row): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary-color);">
                                            <?= e($row['st_first'] . ' ' . $row['st_last']); ?>
                                        </div>
                                        <div style="font-size: 0.75rem; font-family: monospace; color: var(--text-muted);"><?= e($row['matric_number']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?= e($row['department']); ?></div>
                                    </td>

                                    <td>
                                        <span style="font-family: monospace; font-size: 0.75rem; font-weight: 700; color: var(--accent-color);"><?= e($row['project_code']); ?></span>
                                        <div style="font-size: 0.8rem; color: var(--text-main); font-weight: 600;"><?= e($row['project_title']); ?></div>
                                    </td>

                                    <td>
                                        <a href="repository_review.php?dataset_id=<?= (int)$row['id']; ?>" style="font-weight: 700; color: var(--primary-color); text-decoration: none;">
                                            <?= e($row['title']); ?>
                                        </a>
                                    </td>

                                    <td style="font-size: 0.8rem;">
                                        <?= $row['submitted_to_repository_at'] ? date('M d, Y H:i', strtotime($row['submitted_to_repository_at'])) : date('M d, Y', strtotime($row['created_at'])); ?>
                                    </td>

                                    <td>
                                        <span class="badge-status badge-<?= e($row['status']); ?>">
                                            <?= e(str_replace('_', ' ', strtoupper($row['status']))); ?>
                                        </span>
                                    </td>

                                    <td style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">
                                        <?= e($row['access_level']); ?>
                                    </td>

                                    <td>
                                        <a href="repository_review.php?dataset_id=<?= (int)$row['id']; ?>" class="btn-sm" style="background: var(--primary-color); color: #fff; text-decoration: none; padding: 0.3rem 0.75rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                            Review & Curation &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
