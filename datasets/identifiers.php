<?php
/**
 * Dataset Identifiers Management Interface
 * RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/dataset_helpers.php';
require_once __DIR__ . '/citation_helpers.php';

requireAuth();
$user = currentUser();
$userId = $user['id'];
$systemRole = $user['role'];

$datasetId = (int)($_GET['id'] ?? $_POST['dataset_id'] ?? 0);
if ($datasetId <= 0) {
    $_SESSION['dataset_error'] = 'Invalid dataset identifier.';
    header('Location: index.php');
    exit;
}

// Fetch dataset
$stmt = $pdo->prepare("
    SELECT d.*, p.title AS project_title, p.project_code 
    FROM datasets d
    INNER JOIN research_projects p ON d.project_id = p.id
    WHERE d.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $datasetId]);
$dataset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dataset) {
    $_SESSION['dataset_error'] = 'Dataset not found.';
    header('Location: index.php');
    exit;
}

$projectId = (int)$dataset['project_id'];
$ownerId = (int)$dataset['owner_id'];
$projectRole = getProjectMemberRole($pdo, $projectId, $userId);

// Authorization: Dataset Owner, Project Manager/Owner, Librarian, Admin
$canEdit = canEditDataset($projectRole, $systemRole, $ownerId, $userId) || in_array($systemRole, ['librarian', 'admin'], true);
if (!$canEdit) {
    $_SESSION['dataset_error'] = 'Access denied: You do not have permission to manage persistent identifiers for this dataset.';
    header("Location: view.php?id={$datasetId}");
    exit;
}

// Handle Form Submission
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        $errors[] = 'Invalid or expired security token. Please try again.';
    } else {
        $idType  = trim($_POST['identifier_type'] ?? '');
        $idValue = trim($_POST['identifier_value'] ?? '');

        if (empty($idType) || empty($idValue)) {
            $errors[] = 'Identifier type and value are required.';
        } else {
            // Clean up common prefixes
            if (strtoupper($idType) === 'DOI') {
                $idValue = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $idValue);
            }

            if (setDatasetIdentifier($pdo, $datasetId, $idType, $idValue)) {
                log_audit(
                    $pdo,
                    'dataset_identifier_updated',
                    'datasets',
                    $datasetId,
                    "Assigned {$idType} identifier '{$idValue}' to dataset #{$datasetId}",
                    $userId
                );
                $_SESSION['dataset_success'] = "Persistent identifier ({$idType}) updated successfully.";
                header("Location: view.php?id={$datasetId}");
                exit;
            } else {
                $errors[] = 'Failed to save the persistent identifier to database.';
            }
        }
    }
}

$existingIdentifiers = getDatasetIdentifiers($pdo, $datasetId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Identifiers — <?= e($dataset['title']); ?> — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <div class="header-brand">
                <a href="../researcher/dashboard.php" class="brand-link">
                    
                    <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;"> <span class="brand-name">FUD RDM System</span>
                </a>
            </div>
            <div class="header-user">
                <span><?= e($user['first_name'] . ' ' . $user['last_name']); ?></span>
                <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
            </div>
        </header>

        <main class="dashboard-main">
            <div style="margin-bottom: 1.5rem;">
                <a href="view.php?id=<?= $datasetId; ?>" class="btn btn-secondary">← Back to Dataset View</a>
            </div>

            <div class="card" style="max-width: 700px; margin: 0 auto;">
                <div class="card-header">
                    <h2>Manage Persistent Identifiers (PIDs)</h2>
                    <p class="text-muted">Assign DOIs, Handles, ARKs or Institutional PIDs for scholarly citation & discovery.</p>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $err): ?>
                                <div><?= e($err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Current Identifiers -->
                    <?php if (!empty($existingIdentifiers)): ?>
                        <div style="margin-bottom: 2rem;">
                            <h4>Assigned Identifiers</h4>
                            <table class="table" style="margin-top: 0.5rem;">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Identifier Value</th>
                                        <th>Assigned On</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($existingIdentifiers as $idRow): ?>
                                        <tr>
                                            <td><strong><?= e($idRow['identifier_type']); ?></strong></td>
                                            <td><code><?= e($idRow['identifier_value']); ?></code></td>
                                            <td><?= date('Y-m-d H:i', strtotime($idRow['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="identifiers.php?id=<?= $datasetId; ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="dataset_id" value="<?= $datasetId; ?>">

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label for="identifier_type">Identifier Type <span class="text-danger">*</span></label>
                            <select name="identifier_type" id="identifier_type" class="form-control" required>
                                <option value="DOI">DOI (Digital Object Identifier)</option>
                                <option value="Handle">Handle System ID</option>
                                <option value="ARK">ARK (Archival Resource Key)</option>
                                <option value="Institutional">Institutional Persistent ID (Internal)</option>
                                <option value="URN">URN (Uniform Resource Name)</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 1.5rem;">
                            <label for="identifier_value">Identifier Value / String <span class="text-danger">*</span></label>
                            <input type="text" name="identifier_value" id="identifier_value" class="form-control" 
                                   placeholder="e.g. 10.5061/dryad.sample123 or hdl:12345/rdm-456" required>
                            <small class="text-muted">Enter the identifier string or path. Leading https://doi.org/ prefixes will be cleaned automatically.</small>
                        </div>

                        <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                            <a href="view.php?id=<?= $datasetId; ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Identifier</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
