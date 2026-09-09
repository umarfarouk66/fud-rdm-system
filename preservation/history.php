<?php
/**
 * Dataset Preservation & Archival History View
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

$datasetId = (int)($_GET['dataset_id'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['preservation_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and project details
    $dStmt = $pdo->prepare("
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
    $dStmt->execute([':id' => $datasetId]);
    $dataset = $dStmt->fetch(PDO::FETCH_ASSOC);

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['preservation_error'] = 'The requested dataset was not found or has been deleted.';
        header('Location: index.php');
        exit;
    }

    // 2. Fetch preservation history
    $hStmt = $pdo->prepare("
        SELECT 
            pr.*,
            u.first_name AS staff_first_name,
            u.last_name AS staff_last_name,
            u.email AS staff_email
        FROM preservation_records pr
        INNER JOIN users u ON pr.performed_by = u.id
        WHERE pr.dataset_id = :dataset_id
        ORDER BY pr.performed_at DESC, pr.id DESC
    ");
    $hStmt->execute([':dataset_id' => $datasetId]);
    $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

    $isLibrarian = canManagePreservation($systemRole);

} catch (PDOException $e) {
    error_log("Preservation History Query Error: " . $e->getMessage());
    $_SESSION['preservation_error'] = 'Database error loading preservation history.';
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
    <title>Preservation History: <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .history-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.25rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            max-width: 950px;
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
        .timeline {
            position: relative;
            padding-left: 2rem;
            margin-top: 1.5rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0.75rem;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .timeline-dot {
            position: absolute;
            left: -1.65rem;
            top: 1.5rem;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid #ffffff;
            box-shadow: 0 0 0 2px #cbd5e1;
        }
        .checksum-code {
            font-family: monospace;
            font-size: 0.8rem;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.35rem 0.65rem;
            word-break: break-all;
            display: inline-block;
            margin-top: 0.25rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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
        .empty-history {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-muted);
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
        <div style="margin-bottom: 1.5rem; max-width: 950px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Preservation Directory
            </a>
        </div>

        <div class="history-card">

            <div class="header-top">
                <div>
                    <span style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--accent-color); background: var(--primary-light); padding: 0.25rem 0.5rem; border-radius: var(--radius-sm);">
                        <?= e($dataset['project_code']); ?>
                    </span>
                    <span style="margin-left: 0.5rem;">
                        <?= getPreservationStatusBadge($dataset['status']); ?>
                    </span>
                    <h1 style="font-size: 1.65rem; color: var(--primary-color); font-weight: 700; margin-top: 0.4rem; margin-bottom: 0.25rem;">
                        Preservation & Archival History
                    </h1>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Dataset: <strong><?= e($dataset['title']); ?></strong> &bull; Deposited by <?= e($dataset['owner_first_name'] . ' ' . $dataset['owner_last_name']); ?>
                    </div>
                </div>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <?php if ($isLibrarian): ?>
                        <a href="create.php?dataset_id=<?= $datasetId; ?>" class="btn-action btn-action-primary">
                            🛡️ Preserve Version
                        </a>
                        <a href="verify.php?dataset_id=<?= $datasetId; ?>" class="btn-action">
                            🔍 Re-verify Integrity
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($history)): ?>
                <div class="empty-history">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📜</div>
                    <h3>No preservation records have been created yet.</h3>
                    <p style="font-size: 0.9rem; margin-top: 0.5rem;">Preserving a dataset calculates and locks its baseline SHA-256 cryptographic checksum.</p>
                    <?php if ($isLibrarian): ?>
                        <a href="create.php?dataset_id=<?= $datasetId; ?>" class="btn-action btn-action-primary" style="margin-top: 1rem;">
                            🛡️ Execute Preservation Now &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($history as $h): 
                        $meta = parsePreservationNotes($h['notes']);
                        $isArchive = ($h['action'] === 'archive');
                    ?>
                        <div class="timeline-item">
                            <div class="timeline-dot" style="background: <?= $isArchive ? '#334155' : '#059669'; ?>;"></div>

                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <div>
                                    <span style="font-weight: 700; font-size: 1rem; color: <?= $isArchive ? '#1e293b' : '#065f46'; ?>;">
                                        <?= $isArchive ? '📦 Dataset Archival Action' : '🛡️ Long-Term Preservation Record'; ?>
                                    </span>
                                    <?php if (!empty($meta['version_number'])): ?>
                                        <span style="margin-left: 0.5rem; background: #e0e7ff; color: #3730a3; padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                            Version <?= (int)$meta['version_number']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted);">
                                    <?= date('M d, Y \a\t H:i:s', strtotime($h['performed_at'])); ?>
                                </div>
                            </div>

                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                                Executed by <strong><?= e($h['staff_first_name'] . ' ' . $h['staff_last_name']); ?></strong> (<?= e($h['staff_email']); ?>)
                                &bull; Storage Tier: <strong><?= e($meta['location'] ?? 'Institutional Repository'); ?></strong>
                            </div>

                            <?php if (!empty($meta['verified_checksum'])): ?>
                                <div style="margin-bottom: 0.75rem;">
                                    <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Verified SHA-256 Checksum:</div>
                                    <div class="checksum-code"><?= e($meta['verified_checksum']); ?></div>
                                </div>
                            <?php endif; ?>

                            <div style="background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.85rem 1rem; font-size: 0.9rem; line-height: 1.5;">
                                <?= e($meta['preservation_notes'] ?? ($h['notes'] ?: 'Action completed.')); ?>
                            </div>

                            <div style="margin-top: 0.75rem; text-align: right;">
                                <a href="view.php?id=<?= (int)$h['id']; ?>" style="font-size: 0.8rem; color: var(--accent-color); font-weight: 600; text-decoration: none;">
                                    View Full Preservation Record &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Action Bar -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                <a href="index.php" class="btn-action">
                    &larr; Back to Preservation Directory
                </a>
                <a href="../datasets/view.php?id=<?= $datasetId; ?>" class="btn-action">
                    📊 View Dataset Workspace
                </a>
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
