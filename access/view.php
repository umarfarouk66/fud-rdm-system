<?php
/**
 * View Dataset Access Request
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

$requestId = (int)($_GET['id'] ?? 0);

if ($requestId <= 0) {
    $_SESSION['access_error'] = 'Invalid access request identifier.';
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            ar.*,
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.description AS dataset_description,
            d.access_level AS dataset_access_level,
            d.owner_id AS dataset_owner_id,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            req.id AS requester_user_id,
            req.first_name AS requester_first_name,
            req.last_name AS requester_last_name,
            req.email AS requester_email,
            req.institution AS requester_institution,
            req.department AS requester_department,
            rev.first_name AS reviewer_first_name,
            rev.last_name AS reviewer_last_name,
            rev.email AS reviewer_email
        FROM access_requests ar
        INNER JOIN datasets d ON ar.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users req ON ar.requester_id = req.id
        LEFT JOIN users rev ON ar.reviewed_by = rev.id
        WHERE ar.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $_SESSION['access_error'] = 'The requested access request does not exist.';
        header('Location: index.php');
        exit;
    }

    $datasetId = (int)$request['dataset_id'];
    $projectId = (int)$request['project_id'];
    $datasetOwnerId = (int)$request['dataset_owner_id'];
    $requesterId = (int)$request['requester_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    $isRequester = ($userId === $requesterId);
    $canManage = canManageAccessRequests($projectRole, $systemRole, $datasetOwnerId, $userId);

    // Authorization check
    if (!$isRequester && !$canManage) {
        $_SESSION['access_error'] = 'Access denied: You do not have permission to view this access request.';
        header('Location: index.php');
        exit;
    }

} catch (PDOException $e) {
    error_log("Access Request View Error: " . $e->getMessage());
    $_SESSION['access_error'] = 'Database error loading access request.';
    header('Location: index.php');
    exit;
}

// Flash messages
$successMsg = $_SESSION['access_success'] ?? null;
$errorMsg   = $_SESSION['access_error'] ?? null;
unset($_SESSION['access_success'], $_SESSION['access_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Request #<?= $requestId; ?> — FUD RDM System</title>
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

        <!-- Breadcrumbs -->
        <div style="margin-bottom: 1.5rem; max-width: 900px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Access Requests Directory
            </a>
        </div>

        <div class="view-card">

            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($request['project_code']); ?></span>
                    <span style="margin-left: 0.5rem;">
                        <?= getRequestStatusBadge($request['status']); ?>
                    </span>
                    <h1 style="font-size: 1.65rem; color: var(--primary-color); font-weight: 700; margin-top: 0.5rem; margin-bottom: 0.25rem;">
                        Access Request for <?= e($request['dataset_title']); ?>
                    </h1>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Submitted on <?= date('F d, Y \a\t H:i', strtotime($request['created_at'])); ?>
                    </div>
                </div>
                <div>
                    <?php if ($request['status'] === 'approved' && $isRequester): ?>
                        <a href="../datasets/download.php?id=<?= $datasetId; ?>" class="btn-action btn-action-success">
                            ⬇️ Download Dataset File
                        </a>
                    <?php elseif ($request['status'] === 'pending' && $canManage): ?>
                        <a href="review.php?id=<?= $requestId; ?>" class="btn-action btn-action-primary">
                            ⚖️ Review Request
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Requester Profile Box -->
            <div class="section-box">
                <div class="section-title">👤 Requester Profile</div>
                <div class="info-grid">
                    <div>
                        <div class="info-label">Researcher Name</div>
                        <div class="info-value"><strong><?= e($request['requester_first_name'] . ' ' . $request['requester_last_name']); ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Institutional Email</div>
                        <div class="info-value"><?= e($request['requester_email']); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Institution / University</div>
                        <div class="info-value"><?= e($request['requester_institution'] ?: 'Not Specified'); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Department / Faculty</div>
                        <div class="info-value"><?= e($request['requester_department'] ?: 'Not Specified'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Research Justification Box -->
            <div class="section-box">
                <div class="section-title">📄 Research Rationale & Usage Intent</div>
                <div class="text-block"><?= e($request['reason']); ?></div>
            </div>

            <!-- Reviewer Decision & Feedback (If Reviewed) -->
            <?php if ($request['status'] !== 'pending'): ?>
                <div class="section-box" style="background: <?= $request['status'] === 'approved' ? '#f0fdf4' : '#fef2f2'; ?>; border-color: <?= $request['status'] === 'approved' ? '#bbf7d0' : '#fecaca'; ?>;">
                    <div class="section-title" style="color: <?= $request['status'] === 'approved' ? '#065f46' : '#991b1b'; ?>;">
                        ⚖️ Decision & Review Feedback
                    </div>
                    <div class="info-grid" style="margin-bottom: 0.75rem;">
                        <div>
                            <div class="info-label">Reviewed By</div>
                            <div class="info-value"><strong><?= e($request['reviewer_first_name'] . ' ' . $request['reviewer_last_name']); ?></strong></div>
                        </div>
                        <div>
                            <div class="info-label">Review Date</div>
                            <div class="info-value"><?= !empty($request['reviewed_at']) ? date('F d, Y H:i', strtotime($request['reviewed_at'])) : '—'; ?></div>
                        </div>
                        <div>
                            <div class="info-label">Final Outcome</div>
                            <div class="info-value" style="font-weight: 700; color: <?= $request['status'] === 'approved' ? '#059669' : '#dc2626'; ?>;">
                                <?= ucfirst($request['status']); ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($request['reviewer_comment'])): ?>
                        <div>
                            <div class="info-label">Reviewer Comments / Terms</div>
                            <div class="text-block" style="background: #ffffff;"><?= e($request['reviewer_comment']); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Action Bar -->
            <div class="actions-bar">
                <a href="index.php" class="btn-action">
                    &larr; Back to Access Requests
                </a>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="../datasets/view.php?id=<?= $datasetId; ?>" class="btn-action">
                        📊 View Dataset Page
                    </a>
                    <?php if ($request['status'] === 'rejected' && $isRequester): ?>
                        <a href="create.php?dataset_id=<?= $datasetId; ?>" class="btn-action btn-action-primary">
                            🔄 Submit New Access Request
                        </a>
                    <?php endif; ?>
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
