<?php
/**
 * Create Dataset Access Request Form
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

$datasetId = (int)($_GET['dataset_id'] ?? 0);

if ($datasetId <= 0) {
    $_SESSION['access_error'] = 'Please select a valid dataset to request access.';
    header('Location: index.php');
    exit;
}

try {
    // 1. Fetch dataset and project details
    $stmt = $pdo->prepare("
        SELECT 
            d.*,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            p.department AS project_department,
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

    if (!$dataset || $dataset['status'] === 'deleted') {
        $_SESSION['access_error'] = 'The requested dataset does not exist or has been removed.';
        header('Location: index.php');
        exit;
    }

    $projectId = (int)$dataset['project_id'];
    $ownerId = (int)$dataset['owner_id'];
    $projectRole = getProjectMemberRole($pdo, $projectId, $userId);
    $accessLevel = strtolower(trim($dataset['access_level']));

    // 2. Check if dataset is public
    if ($accessLevel === 'public') {
        $infoMessage = 'This dataset is publicly accessible. An access request is not required.';
        $infoActionUrl = "../datasets/download.php?id={$datasetId}";
        $infoActionText = '⬇️ Download Public Dataset';
        $infoType = 'public';
    }
    // 3. Check if dataset is private
    elseif ($accessLevel === 'private' && $userId !== $ownerId && $projectRole === null && $systemRole !== 'admin') {
        $infoMessage = 'This dataset is private. Access is strictly limited to authorized research project members.';
        $infoActionUrl = "../datasets/view.php?id={$datasetId}";
        $infoActionText = 'Back to Dataset Overview';
        $infoType = 'private';
    }
    // 4. Check if user is already owner/member/admin
    elseif ($userId === $ownerId || $projectRole !== null || $systemRole === 'admin') {
        $infoMessage = 'You already have full access to this dataset as an authorized project member or manager.';
        $infoActionUrl = "../datasets/download.php?id={$datasetId}";
        $infoActionText = '⬇️ Download Dataset';
        $infoType = 'already_access';
    }
    else {
        // 5. Check existing access requests
        $existingReq = getUserLatestAccessRequest($pdo, $datasetId, $userId);
        if ($existingReq) {
            if ($existingReq['status'] === 'pending') {
                $infoMessage = 'You already have a pending access request for this dataset currently under review.';
                $infoActionUrl = "view.php?id={$existingReq['id']}";
                $infoActionText = '👁️ View Pending Request';
                $infoType = 'pending';
            } elseif ($existingReq['status'] === 'approved') {
                $infoMessage = 'Your access request has been approved! You already have permission to download this dataset.';
                $infoActionUrl = "../datasets/download.php?id={$datasetId}";
                $infoActionText = '⬇️ Download Dataset';
                $infoType = 'approved';
            } elseif ($existingReq['status'] === 'rejected') {
                $previousRejectionNotice = 'Note: Your previous access request submitted on ' . date('M d, Y', strtotime($existingReq['created_at'])) . ' was not approved (' . e($existingReq['reviewer_comment'] ?: 'No specific reason provided') . '). You may submit a new request with updated research justification.';
            }
        }
    }

} catch (PDOException $e) {
    error_log("Access Request Create Check Error: " . $e->getMessage());
    $_SESSION['access_error'] = 'Database error checking dataset status.';
    header('Location: index.php');
    exit;
}

// Flash errors and old input
$errors = $_SESSION['request_access_errors'] ?? [];
$old    = $_SESSION['old_request_data'] ?? [];
unset($_SESSION['request_access_errors'], $_SESSION['old_request_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Dataset Access — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .form-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-sm);
            max-width: 800px;
            margin: 0 auto 3rem auto;
        }
        .dataset-preview-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.4rem;
        }
        .form-label .required {
            color: #dc2626;
        }
        .form-control {
            width: 100%;
            padding: 0.85rem 1rem;
            font-size: 0.95rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-sizing: border-box;
            background: #ffffff;
            color: var(--text-main);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        textarea.form-control {
            min-height: 140px;
            font-family: inherit;
            resize: vertical;
            line-height: 1.6;
        }
        .form-hint {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            line-height: 1.5;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .btn-submit {
            background-color: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            padding: 0.75rem 1.75rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-size: 0.95rem;
        }
        .btn-submit:hover {
            background-color: #1e40af;
        }
        .btn-cancel {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
        }
        .btn-cancel:hover {
            background: #f8fafc;
            color: var(--text-main);
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
        <div style="margin-bottom: 1.5rem;">
            <a href="../datasets/view.php?id=<?= $datasetId; ?>" style="color: var(--accent-color); text-decoration: none; font-weight: 500; font-size: 0.9rem;">
                &larr; Back to Dataset View
            </a>
        </div>

        <div class="form-card">

            <?php if (isset($infoMessage)): ?>
                <!-- Pre-condition Notice (Public, Private, or Already Accessible) -->
                <div style="text-align: center; padding: 2rem 1rem;">
                    <div style="font-size: 2.5rem; margin-bottom: 1rem;">
                        <?= $infoType === 'public' ? '🌐' : ($infoType === 'private' ? '🔒' : '✅'); ?>
                    </div>
                    <h2 style="font-size: 1.35rem; color: var(--primary-color); margin-bottom: 0.75rem;">
                        <?= e($dataset['title']); ?>
                    </h2>
                    <p style="color: var(--text-muted); font-size: 1rem; margin-bottom: 1.5rem; line-height: 1.6; max-width: 500px; margin-left: auto; margin-right: auto;">
                        <?= e($infoMessage); ?>
                    </p>
                    <a href="<?= e($infoActionUrl); ?>" class="btn-submit" style="display: inline-block; text-decoration: none;">
                        <?= e($infoActionText); ?>
                    </a>
                </div>

            <?php else: ?>

                <div style="margin-bottom: 1.75rem;">
                    <span style="font-family: monospace; font-weight: 700; color: var(--accent-color); font-size: 0.9rem;">
                        <?= e($dataset['project_code']); ?>
                    </span>
                    <h1 style="font-size: 1.75rem; color: var(--primary-color); font-weight: 700; margin-top: 0.25rem; margin-bottom: 0.4rem;">
                        Request Access to Restricted Dataset
                    </h1>
                    <p style="color: var(--text-muted); font-size: 0.95rem;">
                        Submit a research justification to the dataset principal investigator to request controlled data access.
                    </p>
                </div>

                <!-- Previous Rejection Alert if applicable -->
                <?php if (!empty($previousRejectionNotice)): ?>
                    <div style="background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 1rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-size: 0.9rem;">
                        <?= e($previousRejectionNotice); ?>
                    </div>
                <?php endif; ?>

                <!-- Error Alerts -->
                <?php if (!empty($errors)): ?>
                    <div class="dash-alert dash-alert-danger" style="display: block; margin-bottom: 1.5rem;">
                        <strong>Please address the following:</strong>
                        <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Dataset Summary Box -->
                <div class="dataset-preview-box">
                    <h3 style="font-size: 1.05rem; color: var(--primary-color); margin-bottom: 0.4rem;">
                        <?= e($dataset['title']); ?>
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--text-main); line-height: 1.5; margin-bottom: 0.75rem;">
                        <?= e($dataset['description']); ?>
                    </p>
                    <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 1.25rem; flex-wrap: wrap;">
                        <div><strong>Project:</strong> <?= e($dataset['project_title']); ?></div>
                        <div><strong>Custodian:</strong> <?= e($dataset['owner_first_name'] . ' ' . $dataset['owner_last_name']); ?></div>
                        <div><strong>Access Level:</strong> <span style="text-transform: uppercase; font-weight: 700; color: #b45309;"><?= e($dataset['access_level']); ?></span></div>
                    </div>
                </div>

                <!-- Access Request Form -->
                <form action="store.php" method="POST" autocomplete="off">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="dataset_id" value="<?= (int)$dataset['id']; ?>">

                    <div class="form-group">
                        <label for="reason" class="form-label">
                            Research Justification & Purpose of Use <span class="required">*</span>
                        </label>
                        <textarea id="reason" name="reason" class="form-control" placeholder="Please clearly describe:&#10;1. Why you require access to this specific dataset&#10;2. Your intended academic research objectives and methodology&#10;3. Data security, privacy safeguards and storage handling protocols you will apply" required><?= e($old['reason'] ?? ''); ?></textarea>
                        <p class="form-hint">
                            Your justification will be reviewed directly by the dataset custodian and project lead before authorization is granted.
                        </p>
                    </div>

                    <div class="form-actions">
                        <a href="../datasets/view.php?id=<?= $datasetId; ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">
                            Submit Access Request &rarr;
                        </button>
                    </div>
                </form>

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
