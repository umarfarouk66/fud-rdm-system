<?php
/**
 * Dataset Access Requests Directory
 * RDM Information System - Step 9
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/access_helpers.php';

// Require authenticated session
requireAuth();

$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

// Flash notifications
$successMsg = $_SESSION['access_success'] ?? null;
$errorMsg   = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_success'], $_SESSION['access_error']);

// 1. Fetch requests submitted by the logged-in user
$myRequests = [];
try {
    $myStmt = $pdo->prepare("
        SELECT 
            ar.*,
            d.title AS dataset_title,
            d.access_level AS dataset_access_level,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            rev.first_name AS reviewer_first_name,
            rev.last_name AS reviewer_last_name
        FROM access_requests ar
        INNER JOIN datasets d ON ar.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        LEFT JOIN users rev ON ar.reviewed_by = rev.id
        WHERE ar.requester_id = :user_id
        ORDER BY ar.created_at DESC
    ");
    $myStmt->execute([':user_id' => $userId]);
    $myRequests = $myStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("My Access Requests Query Error: " . $e->getMessage());
}

// 2. Fetch incoming requests for datasets managed by the logged-in user
$incomingRequests = [];
try {
    if ($systemRole === 'admin') {
        $inStmt = $pdo->query("
            SELECT 
                ar.*,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                req.first_name AS requester_first_name,
                req.last_name AS requester_last_name,
                req.email AS requester_email,
                req.institution AS requester_institution,
                req.department AS requester_department,
                rev.first_name AS reviewer_first_name,
                rev.last_name AS reviewer_last_name
            FROM access_requests ar
            INNER JOIN datasets d ON ar.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users req ON ar.requester_id = req.id
            LEFT JOIN users rev ON ar.reviewed_by = rev.id
            ORDER BY FIELD(ar.status, 'pending', 'approved', 'rejected', 'revoked'), ar.created_at DESC
        ");
        $incomingRequests = $inStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $inStmt = $pdo->prepare("
            SELECT 
                ar.*,
                d.title AS dataset_title,
                d.access_level AS dataset_access_level,
                p.id AS project_id,
                p.project_code,
                p.title AS project_title,
                req.first_name AS requester_first_name,
                req.last_name AS requester_last_name,
                req.email AS requester_email,
                req.institution AS requester_institution,
                req.department AS requester_department,
                rev.first_name AS reviewer_first_name,
                rev.last_name AS reviewer_last_name
            FROM access_requests ar
            INNER JOIN datasets d ON ar.dataset_id = d.id
            INNER JOIN research_projects p ON d.project_id = p.id
            INNER JOIN users req ON ar.requester_id = req.id
            LEFT JOIN users rev ON ar.reviewed_by = rev.id
            LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
            WHERE d.owner_id = :user_id OR (pm.user_id = :user_id AND pm.role IN ('owner', 'manager'))
            GROUP BY ar.id
            ORDER BY FIELD(ar.status, 'pending', 'approved', 'rejected', 'revoked'), ar.created_at DESC
        ");
        $inStmt->execute([':user_id' => $userId]);
        $incomingRequests = $inStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Incoming Access Requests Query Error: " . $e->getMessage());
}

$pendingCount = 0;
foreach ($incomingRequests as $ir) {
    if ($ir['status'] === 'pending') {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dataset Access Requests — FUD RDM System</title>
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
        .page-title p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .section-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 2.5rem;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .section-header-bar {
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.9rem;
        }
        .data-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            padding: 0.85rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .data-table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
            vertical-align: middle;
        }
        .data-table tr:last-child td {
            border-bottom: none;
        }
        .data-table tr:hover td {
            background-color: #f8fafc;
        }
        .project-code-badge {
            font-family: monospace;
            font-weight: 700;
            color: var(--accent-color);
            background: var(--primary-light);
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            margin-right: 0.35rem;
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
        .empty-placeholder {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .pending-pill {
            background: #fef3c7;
            color: #92400e;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <header class="dash-navbar">
        <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="dash-brand">
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

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1>Dataset Access Requests & Sharing</h1>
                <p>Manage access permissions, compliance reviews and data sharing for restricted research datasets</p>
            </div>
            <div>
                <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="btn-action" style="padding: 0.55rem 1rem; font-size: 0.9rem;">
                    &larr; Dashboard
                </a>
                <a href="../datasets/index.php" class="btn-action btn-action-primary" style="padding: 0.55rem 1.1rem; font-size: 0.9rem;">
                    🔍 Explore Datasets
                </a>
            </div>
        </div>

        <!-- Section 1: Incoming Requests to Review (For Dataset Owners / Managers) -->
        <?php if (!empty($incomingRequests) || $systemRole === 'admin'): ?>
            <div class="section-card">
                <div class="section-header-bar">
                    <h2 class="section-title">
                        📥 Incoming Access Requests to Review
                        <?php if ($pendingCount > 0): ?>
                            <span class="pending-pill"><?= $pendingCount; ?> Pending Action</span>
                        <?php endif; ?>
                    </h2>
                </div>

                <?php if (empty($incomingRequests)): ?>
                    <div class="empty-placeholder">
                        No access requests are waiting for review.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Dataset</th>
                                    <th>Requester</th>
                                    <th>Reason Summary</th>
                                    <th>Status</th>
                                    <th>Requested Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomingRequests as $req): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: var(--primary-color);">
                                                <?= e($req['dataset_title']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                                                <span class="project-code-badge"><?= e($req['project_code']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?= e($req['requester_first_name'] . ' ' . $req['requester_last_name']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted);"><?= e($req['requester_department'] ?: $req['requester_institution']); ?></div>
                                        </td>
                                        <td>
                                            <div style="max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($req['reason']); ?>">
                                                <?= e($req['reason']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?= getRequestStatusBadge($req['status']); ?>
                                        </td>
                                        <td>
                                            <?= date('M d, Y H:i', strtotime($req['created_at'])); ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <a href="review.php?id=<?= (int)$req['id']; ?>" class="btn-action btn-action-primary">
                                                    ⚖️ Review Request
                                                </a>
                                            <?php else: ?>
                                                <a href="view.php?id=<?= (int)$req['id']; ?>" class="btn-action">
                                                    👁️ View Decision
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Section 2: My Submitted Access Requests -->
        <div class="section-card">
            <div class="section-header-bar">
                <h2 class="section-title">
                    📤 My Submitted Access Requests
                </h2>
            </div>

            <?php if (empty($myRequests)): ?>
                <div class="empty-placeholder">
                    <p style="margin-bottom: 0.5rem;">No access requests yet.</p>
                    <p style="font-size: 0.85rem;">When you encounter a restricted dataset in the repository, you can submit an access request specifying your research rationale.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Dataset</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Submitted Date</th>
                                <th>Reviewed Date</th>
                                <th>Review Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myRequests as $mr): ?>
                                <tr>
                                    <td>
                                        <a href="../datasets/view.php?id=<?= (int)$mr['dataset_id']; ?>" style="font-weight: 600; color: var(--primary-color); text-decoration: none;">
                                            <?= e($mr['dataset_title']); ?>
                                        </a>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                                            <span class="project-code-badge"><?= e($mr['project_code']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($mr['reason']); ?>">
                                            <?= e($mr['reason']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= getRequestStatusBadge($mr['status']); ?>
                                    </td>
                                    <td>
                                        <?= date('M d, Y', strtotime($mr['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?= !empty($mr['reviewed_at']) ? date('M d, Y', strtotime($mr['reviewed_at'])) : '—'; ?>
                                    </td>
                                    <td>
                                        <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($mr['reviewer_comment'] ?? ''); ?>">
                                            <?= !empty($mr['reviewer_comment']) ? e($mr['reviewer_comment']) : '<span style="color: #94a3b8; font-style: italic;">None</span>'; ?>
                                        </div>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="view.php?id=<?= (int)$mr['id']; ?>" class="btn-action">
                                            👁️ Details
                                        </a>
                                        <?php if ($mr['status'] === 'approved'): ?>
                                            <a href="../datasets/download.php?id=<?= (int)$mr['dataset_id']; ?>" class="btn-action btn-action-success">
                                                ⬇️ Download Data
                                            </a>
                                        <?php elseif ($mr['status'] === 'rejected'): ?>
                                            <a href="create.php?dataset_id=<?= (int)$mr['dataset_id']; ?>" class="btn-action btn-action-primary">
                                                🔄 Re-apply
                                            </a>
                                        <?php endif; ?>
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
