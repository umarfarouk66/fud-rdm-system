<?php
/**
 * Backup & Disaster Recovery Center
 * RDM Information System - Step 16 Compliance
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/backup_helpers.php';

requireRole('admin');

$admin = currentUser();
$adminId = (int)$admin['id'];

$actionMsg = null;
$errorMsg = null;

// Handle manual backup trigger or verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceWritePermission();
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        $errorMsg = 'Invalid or expired security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_db_backup') {
            $res = createDatabaseSnapshot($pdo, $adminId);
            if ($res['success']) {
                $actionMsg = "Database backup snapshot '{$res['record']['filename']}' created successfully.";
            } else {
                $errorMsg = "Failed to create database backup: " . ($res['error'] ?? 'Unknown error');
            }
        } elseif ($action === 'verify_backup') {
            $backupId = trim($_POST['backup_id'] ?? '');
            $res = verifyBackupSnapshot($backupId);
            if ($res['valid']) {
                $actionMsg = "Backup snapshot {$backupId} verified successfully (SHA-256 Checksum Match).";
            } else {
                $errorMsg = "Verification failed for {$backupId}: " . ($res['error'] ?? 'Corrupted or missing');
            }
        }
    }
}

$backups = getBackupRecords();

// Calculate total backup storage size
$totalBackupBytes = 0;
foreach ($backups as $b) {
    $totalBackupBytes += (int)($b['file_size'] ?? 0);
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' Bytes';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup & Disaster Recovery Center — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .strategy-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .concept-diff {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 768px) {
            .concept-diff { grid-template-columns: 1fr; }
        }
        .diff-card {
            padding: 1.25rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }
        .status-badge-verified { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 9999px; }
        .status-badge-corrupted { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 9999px; }
        .status-badge-missing { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-weight: 700; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 9999px; }
    </style>
</head>
<body>
    <div class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Backup & Disaster Recovery</div>
            </div>
        </a>
        <div class="dash-user-controls">
            <a href="dashboard.php" class="btn-logout" style="margin-right: 0.5rem;">Dashboard</a>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </div>

    <main class="dash-container">
        <div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.25rem;">
                    💾 Backup & Disaster Recovery Center
                </h1>
                <p style="color: var(--text-muted); font-size: 0.95rem;">
                    Institutional database snapshotting, repository file redundancy policies and disaster recovery procedures.
                </p>
            </div>
            <form method="POST" action="backups.php" onsubmit="return confirm('Generate a full database backup snapshot now?');">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="create_db_backup">
                <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem; font-weight: 700;">
                    ⚡ Generate Manual DB Snapshot
                </button>
            </form>
        </div>

        <?php if ($actionMsg): ?>
            <div class="dash-alert dash-alert-success" style="margin-bottom: 1.5rem;">
                <?= e($actionMsg); ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="dash-alert dash-alert-danger" style="margin-bottom: 1.5rem;">
                <?= e($errorMsg); ?>
            </div>
        <?php endif; ?>

        <!-- Architectural Concept Distinction -->
        <div class="concept-diff">
            <div class="diff-card" style="background: #f0fdf4; border-color: #bbf7d0;">
                <h3 style="color: #065f46; font-size: 1.1rem; margin-bottom: 0.5rem;">
                    🏛️ Digital Preservation (Curation)
                </h3>
                <p style="font-size: 0.875rem; color: #047857; line-height: 1.5;">
                    Preservation guarantees the <strong>long-term scholarly integrity and bit-level authenticity</strong> of research data assets using continuous SHA-256 checksum verification, format migration and immutable curation histories.
                </p>
            </div>
            <div class="diff-card" style="background: #eff6ff; border-color: #bfdbfe;">
                <h3 style="color: #1e40af; font-size: 1.1rem; margin-bottom: 0.5rem;">
                    💾 Backup & Disaster Recovery (Operational)
                </h3>
                <p style="font-size: 0.875rem; color: #1e3a8a; line-height: 1.5;">
                    Backups guarantee <strong>system recoverability after storage, hardware, or catastrophic failure</strong> through scheduled database dumps, repository file synchronization and offline cold-storage replication.
                </p>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="metrics-grid" style="margin-bottom: 2rem;">
            <div class="metric-card">
                <div class="metric-icon" style="background: #e0f2fe; color: #0284c7;">📁</div>
                <div class="metric-data">
                    <div class="metric-value"><?= count($backups); ?></div>
                    <div class="metric-label">Total Snapshots</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon" style="background: #f0fdf4; color: #16a34a;">💾</div>
                <div class="metric-data">
                    <div class="metric-value"><?= formatBytes($totalBackupBytes); ?></div>
                    <div class="metric-label">Backup Storage Used</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon" style="background: #fef3c7; color: #d97706;">🕒</div>
                <div class="metric-data">
                    <div class="metric-value"><?= !empty($backups[0]['created_at']) ? date('M d, H:i', strtotime($backups[0]['created_at'])) : 'None'; ?></div>
                    <div class="metric-label">Latest Snapshot</div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon" style="background: #f1f5f9; color: #475569;">🛡️</div>
                <div class="metric-data">
                    <div class="metric-value">Active (3-2-1)</div>
                    <div class="metric-label">Strategy Status</div>
                </div>
            </div>
        </div>

        <!-- Backup History Table -->
        <div class="section-card" style="margin-bottom: 2rem;">
            <h2 class="section-heading">📜 Backup Snapshot History & Verification</h2>
            <?php if (empty($backups)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                    No backup snapshots recorded yet. Click "Generate Manual DB Snapshot" above to create the first baseline.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-color); text-align: left;">
                                <th style="padding: 0.75rem;">Snapshot ID</th>
                                <th style="padding: 0.75rem;">Type</th>
                                <th style="padding: 0.75rem;">Filename</th>
                                <th style="padding: 0.75rem;">Size</th>
                                <th style="padding: 0.75rem;">SHA-256 Checksum</th>
                                <th style="padding: 0.75rem;">Created</th>
                                <th style="padding: 0.75rem;">Status</th>
                                <th style="padding: 0.75rem; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $bk): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 0.75rem; font-weight: 700;"><?= e($bk['id']); ?></td>
                                    <td style="padding: 0.75rem;"><span style="text-transform: capitalize; font-size: 0.85rem;"><?= e(str_replace('_', ' ', $bk['type'])); ?></span></td>
                                    <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;"><?= e($bk['filename']); ?></td>
                                    <td style="padding: 0.75rem;"><?= formatBytes((int)($bk['file_size'] ?? 0)); ?></td>
                                    <td style="padding: 0.75rem;"><code style="font-size: 0.75rem;"><?= substr(e($bk['checksum'] ?? ''), 0, 16); ?>...</code></td>
                                    <td style="padding: 0.75rem; font-size: 0.85rem;"><?= date('Y-m-d H:i', strtotime($bk['created_at'])); ?></td>
                                    <td style="padding: 0.75rem;">
                                        <?php if (($bk['status'] ?? '') === 'verified'): ?>
                                            <span class="status-badge-verified">✅ Verified</span>
                                        <?php elseif (($bk['status'] ?? '') === 'corrupted'): ?>
                                            <span class="status-badge-corrupted">❌ Corrupted</span>
                                        <?php else: ?>
                                            <span class="status-badge-missing">⚠️ Missing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.75rem; text-align: right;">
                                        <form method="POST" action="backups.php" style="display: inline;">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="verify_backup">
                                            <input type="hidden" name="backup_id" value="<?= e($bk['id']); ?>">
                                            <button type="submit" class="btn-header" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                                                🔍 Verify
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Institutional Disaster Recovery & Restore Workflow Documentation -->
        <div class="strategy-box">
            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--text-main); margin-bottom: 1rem;">
                📋 Disaster Recovery & Restore Procedures (Runbook)
            </h2>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div>
                    <h3 style="font-size: 1rem; color: #1e40af; margin-bottom: 0.5rem;">1. Database Recovery Procedure</h3>
                    <ol style="font-size: 0.875rem; color: var(--text-main); line-height: 1.6; padding-left: 1.25rem;">
                        <li>Locate the verified snapshot file in <code>storage/backups/rdm_db_backup_[TIMESTAMP].sql</code>.</li>
                        <li>Verify checksum matches baseline: <code>sha256sum rdm_db_backup_[TIMESTAMP].sql</code>.</li>
                        <li>Connect to MySQL command interface: <code>mysql -u [USER] -p rdm_system < rdm_db_backup_[TIMESTAMP].sql</code>.</li>
                        <li>Verify table counts and row integrity in <code>admin/system_status.php</code>.</li>
                    </ol>
                </div>
                <div>
                    <h3 style="font-size: 1rem; color: #1e40af; margin-bottom: 0.5rem;">2. Repository Files Recovery Procedure</h3>
                    <ol style="font-size: 0.875rem; color: var(--text-main); line-height: 1.6; padding-left: 1.25rem;">
                        <li>Restore dataset version files from off-site replica into <code>storage/datasets/</code>.</li>
                        <li>Ensure filesystem read/write permissions (0750) on Apache runtime.</li>
                        <li>Run preservation verification across all datasets in <code>preservation/index.php</code> to confirm SHA-256 match.</li>
                    </ol>
                </div>
            </div>
            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-sm); font-size: 0.85rem; color: #92400e;">
                ⚠️ <strong>Deployment Scheduling Note:</strong> Automated scheduled cron backups (e.g. daily at 02:00 UTC) must be configured by the deployment server administrator using server cron / task scheduler executing backup CLI triggers.
            </div>
        </div>
    </main>
</body>
</html>
