<?php
/**
 * Librarian / Data Steward Dashboard
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
require_once __DIR__ . '/../notifications/notification_helper.php';

// Enforce librarian role
requireRole('librarian');

$user = currentUser();
$userId = (int)$user['id'];
$accessError = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_error']);

$notifUnreadCount = getUnreadNotificationCount($pdo, $userId);

// Dynamic counts
$totalDatasets       = 0;
$preservedDatasets   = 0;
$archivedDatasets    = 0;
$awaitingDatasets    = 0;
$pendingRepoSubmissions = 0;
$totalPreservationRec = 0;
$recentPreservations = [];

try {
    // 1. Total datasets in system
    $stmt = $pdo->query("SELECT COUNT(*) FROM datasets WHERE status != 'deleted'");
    $totalDatasets = (int)$stmt->fetchColumn();

    // 2. Preserved datasets
    $stmt = $pdo->query("SELECT COUNT(*) FROM datasets WHERE status = 'preserved'");
    $preservedDatasets = (int)$stmt->fetchColumn();

    // 3. Archived datasets
    $stmt = $pdo->query("SELECT COUNT(*) FROM datasets WHERE status = 'archived'");
    $archivedDatasets = (int)$stmt->fetchColumn();

    // 4. Awaiting preservation
    $stmt = $pdo->query("SELECT COUNT(*) FROM datasets WHERE status NOT IN ('preserved', 'archived', 'deleted')");
    $awaitingDatasets = (int)$stmt->fetchColumn();

    // 4b. Pending Repository Submissions (Phase 9)
    $stmt = $pdo->query("SELECT COUNT(*) FROM datasets WHERE status = 'submitted'");
    $pendingRepoSubmissions = (int)$stmt->fetchColumn();

    // 5. Total preservation records executed
    $stmt = $pdo->query("SELECT COUNT(*) FROM preservation_records");
    $totalPreservationRec = (int)$stmt->fetchColumn();

    // 6. Recent preservation activity
    $stmt = $pdo->query("
        SELECT 
            pr.*,
            d.title AS dataset_title,
            d.id AS dataset_id,
            d.status AS dataset_status,
            p.project_code,
            u.first_name AS staff_first_name,
            u.last_name AS staff_last_name
        FROM preservation_records pr
        INNER JOIN datasets d ON pr.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON pr.performed_by = u.id
        ORDER BY pr.performed_at DESC, pr.id DESC
        LIMIT 5
    ");
    $recentPreservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Librarian Dashboard Query Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian & Data Steward Dashboard — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .recent-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            margin-top: 0.75rem;
        }
        .recent-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
        }
        .recent-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .recent-table tr:last-child td {
            border-bottom: none;
        }
        .metric-breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .metric-pill {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.75rem;
            text-align: center;
        }
        .metric-num {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .metric-lbl {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 0.2rem;
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
            <a href="dashboard.php" class="btn-home-nav" style="text-decoration: none; color: #1e3a8a; display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 700; background: #ffffff; padding: 0.45rem 0.9rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); font-size: 0.85rem; margin-right: 0.5rem;" title="Go to Home Dashboard">
                <span>🏠 Home</span>
            </a>
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
                    <div class="user-affiliation">
                        <?= e($user['institution'] ?: 'Institutional Repository Library'); ?>
                    </div>
                </div>
                <span class="role-badge role-badge-librarian">Librarian / Steward</span>
            </div>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Flash Notice if redirected -->
        <?php if (!empty($accessError)): ?>
            <div class="dash-alert dash-alert-danger">
                <span><?= e($accessError); ?></span>
            </div>
        <?php endif; ?>

        <!-- Welcome Banner -->
        <div class="dash-welcome" style="background: linear-gradient(135deg, #064e3b 0%, #065f46 100%);">
            <div class="welcome-text">
                <h1>Welcome, <?= e($user['first_name']); ?>!</h1>
                <p>Curate descriptive metadata, verify cryptographic file checksums and manage institutional digital preservation and archival pipelines.</p>
            </div>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="repository_review.php" style="display: inline-flex; align-items: center; background: #ffffff; color: #065f46; font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                    📚 Repository Curation & Review Console (<?= $pendingRepoSubmissions; ?> Pending)
                </a>
                <a href="../reports/generate.php" style="display: inline-flex; align-items: center; background: #2563eb; color: #ffffff; font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none; box-shadow: var(--shadow-sm);">
                    ⚡ Generate Report
                </a>
                <a href="../reports/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.25rem; text-decoration: none;">
                    📊 Reports & Analytics
                </a>
                <a href="../projects/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none;">
                    📁 Research Projects Directory
                </a>
                <a href="../preservation/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none;">
                    🏛️ Preservation Console
                </a>
                <a href="../metadata/index.php" style="display: inline-flex; align-items: center; background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 700; padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); text-decoration: none;">
                    🏷️ Curate Metadata
                </a>
            </div>
        </div>

        <!-- Metric Cards Grid -->
        <div class="stats-grid">
            <a href="../datasets/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-emerald">📊</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $totalDatasets; ?></div>
                        <div class="stat-label">Total Datasets</div>
                    </div>
                </div>
            </a>

            <a href="../preservation/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-blue">🛡️</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $preservedDatasets; ?></div>
                        <div class="stat-label">Preserved Datasets</div>
                    </div>
                </div>
            </a>

            <a href="../preservation/index.php" style="text-decoration: none; color: inherit;">
                <div class="stat-card">
                    <div class="stat-icon-box icon-purple">⏳</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $awaitingDatasets; ?></div>
                        <div class="stat-label">Awaiting Preservation</div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Digital Preservation & Archival Overview Card -->
        <div class="content-card" style="margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="section-title" style="margin-bottom: 0;">Preservation & Archival Repository Metrics</h2>
                <a href="../preservation/index.php" style="color: var(--accent-color); text-decoration: none; font-size: 0.85rem; font-weight: 600;">
                    Open Preservation Console &rarr;
                </a>
            </div>

            <div class="metric-breakdown-grid">
                <div class="metric-pill">
                    <div class="metric-num" style="color: #059669;"><?= $preservedDatasets; ?></div>
                    <div class="metric-lbl">Preserved</div>
                </div>
                <div class="metric-pill">
                    <div class="metric-num" style="color: #d97706;"><?= $awaitingDatasets; ?></div>
                    <div class="metric-lbl">Awaiting Action</div>
                </div>
                <div class="metric-pill">
                    <div class="metric-num" style="color: #475569;"><?= $archivedDatasets; ?></div>
                    <div class="metric-lbl">Archived</div>
                </div>
                <div class="metric-pill">
                    <div class="metric-num" style="color: #2563eb;"><?= $totalPreservationRec; ?></div>
                    <div class="metric-lbl">Audit Executions</div>
                </div>
            </div>
        </div>

        <!-- Recent Preservation Activity -->
        <div class="content-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 class="section-title" style="margin-bottom: 0;">Recent Preservation & Archival Activity</h2>
                <a href="../preservation/index.php" style="color: var(--accent-color); text-decoration: none; font-size: 0.85rem; font-weight: 600;">
                    View All &rarr;
                </a>
            </div>

            <?php if (empty($recentPreservations)): ?>
                <div style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                    <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🏛️</div>
                    <p>No preservation or archival activity has been recorded yet.</p>
                </div>
            <?php else: ?>
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Dataset</th>
                            <th>Action</th>
                            <th>Executed By</th>
                            <th>Timestamp</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPreservations as $rp): ?>
                            <tr>
                                <td>
                                    <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); background: var(--primary-light); padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); font-size: 0.8rem;">
                                        <?= e($rp['project_code']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="../datasets/view.php?id=<?= (int)$rp['dataset_id']; ?>" style="color: var(--text-main); font-weight: 600; text-decoration: none;">
                                        <?= e($rp['dataset_title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span style="padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: <?= $rp['action'] === 'archive' ? '#f1f5f9; color: #334155;' : '#d1fae5; color: #065f46;'; ?>">
                                        <?= e($rp['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e($rp['staff_first_name'] . ' ' . $rp['staff_last_name']); ?>
                                </td>
                                <td>
                                    <?= date('M d, Y H:i', strtotime($rp['performed_at'])); ?>
                                </td>
                                <td>
                                    <a href="../preservation/view.php?id=<?= (int)$rp['id']; ?>" style="color: var(--accent-color); font-size: 0.85rem; font-weight: 600; text-decoration: none;">
                                        Record &rarr;
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
