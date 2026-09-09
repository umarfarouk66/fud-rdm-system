<?php
/**
 * Review Dataset Access Request View
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
            req.first_name AS requester_first_name,
            req.last_name AS requester_last_name,
            req.email AS requester_email,
            req.institution AS requester_institution,
            req.department AS requester_department
        FROM access_requests ar
        INNER JOIN datasets d ON ar.dataset_id = d.id
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users req ON ar.requester_id = req.id
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
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);

    // Enforce reviewer authorization
    if (!canManageAccessRequests($projectRole, $systemRole, $datasetOwnerId, $userId)) {
        $_SESSION['access_error'] = 'Access denied: You do not have permission to review access requests for this dataset.';
        header('Location: index.php');
        exit;
    }

    // Check if already reviewed (concurrency guard)
    if ($request['status'] !== 'pending') {
        $_SESSION['access_error'] = 'This access request has already been reviewed (Status: ' . ucfirst($request['status']) . ').';
        header("Location: view.php?id={$requestId}");
        exit;
    }

} catch (PDOException $e) {
    error_log("Access Request Review Query Error: " . $e->getMessage());
    $_SESSION['access_error'] = 'Database error verifying reviewer authorization.';
    header('Location: index.php');
    exit;
}

// Flash errors
$errors = $_SESSION['review_errors'] ?? [];
unset($_SESSION['review_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Access Request #<?= $requestId; ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .review-card {
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
            padding: 1.2rem;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            margin-top: 0.35rem;
        }
        .decision-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 2rem;
        }
        @media (max-width: 768px) {
            .decision-container {
                grid-template-columns: 1fr;
            }
        }
        .decision-box {
            border-radius: var(--radius-md);
            padding: 1.75rem;
            border: 1px solid;
        }
        .decision-box-approve {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }
        .decision-box-reject {
            background: #fef2f2;
            border-color: #fecaca;
        }
        .decision-heading {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .decision-box-approve .decision-heading {
            color: #065f46;
        }
        .decision-box-reject .decision-heading {
            color: #991b1b;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem 0.85rem;
            font-size: 0.9rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-sizing: border-box;
            background: #ffffff;
            color: var(--text-main);
            margin-top: 0.5rem;
            margin-bottom: 1rem;
        }
        textarea.form-control {
            min-height: 90px;
            font-family: inherit;
            resize: vertical;
        }
        .btn-decision {
            width: 100%;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-decision-approve {
            background-color: #059669;
            color: #ffffff;
        }
        .btn-decision-approve:hover {
            background-color: #047857;
        }
        .btn-decision-reject {
            background-color: #dc2626;
            color: #ffffff;
        }
        .btn-decision-reject:hover {
            background-color: #b91c1c;
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

        <!-- Top Breadcrumb -->
        <div style="margin-bottom: 1.5rem; max-width: 900px; margin-left: auto; margin-right: auto;">
            <a href="index.php" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Access Requests Directory
            </a>
        </div>

        <div class="review-card">

            <div class="header-top">
                <div>
                    <span class="project-code-tag"><?= e($request['project_code']); ?></span>
                    <span style="margin-left: 0.5rem;">
                        <?= getRequestStatusBadge($request['status']); ?>
                    </span>
                    <h1 style="font-size: 1.65rem; color: var(--primary-color); font-weight: 700; margin-top: 0.5rem; margin-bottom: 0.25rem;">
                        Review Dataset Access Request
                    </h1>
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Submitted on <?= date('F d, Y \a\t H:i', strtotime($request['created_at'])); ?>
                    </div>
                </div>
                <div>
                    <a href="view.php?id=<?= $requestId; ?>" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;">
                        View Request Details &rarr;
                    </a>
                </div>
            </div>

            <!-- Error Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 1.5rem;">
                    <strong>Review Error:</strong>
                    <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Dataset Context -->
            <div class="section-box">
                <div class="section-title">📊 Dataset Being Requested</div>
                <div class="info-grid">
                    <div style="grid-column: 1 / -1;">
                        <div class="info-label">Dataset Title</div>
                        <div class="info-value"><strong><?= e($request['dataset_title']); ?></strong></div>
                    </div>
                    <div>
                        <div class="info-label">Research Project</div>
                        <div class="info-value"><?= e($request['project_title']); ?></div>
                    </div>
                    <div>
                        <div class="info-label">Access Level</div>
                        <div class="info-value" style="color: #b45309; font-weight: 700; text-transform: uppercase;">
                            <?= e($request['dataset_access_level']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requester Profile -->
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

            <!-- Research Justification -->
            <div class="section-box">
                <div class="section-title">📄 Research Rationale & Usage Intent</div>
                <div class="text-block"><?= e($request['reason']); ?></div>
            </div>

            <!-- Decision Options -->
            <div class="decision-container">

                <!-- Approve Action Form -->
                <div class="decision-box decision-box-approve">
                    <div class="decision-heading">✅ Grant Access (Approve)</div>
                    <p style="font-size: 0.85rem; color: #047857; line-height: 1.5; margin-bottom: 1rem;">
                        Grants the requesting researcher authorization to download files associated with this restricted dataset.
                    </p>
                    <form action="approve.php" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">

                        <label for="approve_comments" style="font-size: 0.8rem; font-weight: 700; color: #065f46;">
                            Approval Terms & Notes (Optional):
                        </label>
                        <textarea id="approve_comments" name="reviewer_comment" class="form-control" placeholder="Specify any attribution guidelines, citation terms or validity period..."></textarea>

                        <button type="submit" class="btn-decision btn-decision-approve" onclick="return confirm('Confirm: Approve data access for this researcher?');">
                            Approve & Grant Access &rarr;
                        </button>
                    </form>
                </div>

                <!-- Reject Action Form -->
                <div class="decision-box decision-box-reject">
                    <div class="decision-heading">❌ Decline Access (Reject)</div>
                    <p style="font-size: 0.85rem; color: #b91c1c; line-height: 1.5; margin-bottom: 1rem;">
                        Denies access to the restricted data files. The researcher will be informed of your review explanation.
                    </p>
                    <form action="reject.php" method="POST">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="request_id" value="<?= $requestId; ?>">

                        <label for="reject_comments" style="font-size: 0.8rem; font-weight: 700; color: #991b1b;">
                            Reason for Rejection <span style="color: #dc2626;">*</span>:
                        </label>
                        <textarea id="reject_comments" name="reviewer_comment" class="form-control" placeholder="Explain why the request cannot be granted at this time..." required></textarea>

                        <button type="submit" class="btn-decision btn-decision-reject" onclick="return confirm('Confirm: Reject this access request?');">
                            Decline Access Request &rarr;
                        </button>
                    </form>
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
