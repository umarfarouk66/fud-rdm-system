<?php
/**
 * Interoperability & External Integrations Administration
 * RDM Information System - Step 16 Compliance
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/interoperability.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/admin_helpers.php';

requireRole('admin');
enforceWritePermission();

$admin = currentUser();
$adminId = (int)$admin['id'];

$actionMsg = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token($token)) {
        $errorMsg = 'Invalid or expired security token. Please try again.';
    } else {
        $currentSettings = getInteropSettings();

        // Update enable toggles safely
        $currentSettings['saml_sso']['enabled']             = !empty($_POST['saml_sso_enabled']);
        $currentSettings['orcid']['enabled']                = !empty($_POST['orcid_enabled']);
        $currentSettings['oai_pmh']['enabled']              = !empty($_POST['oai_pmh_enabled']);
        $currentSettings['library_systems']['enabled']      = !empty($_POST['library_systems_enabled']);
        $currentSettings['external_repositories']['enabled']= !empty($_POST['external_repositories_enabled']);

        if (!empty($_POST['orcid_client_id'])) {
            $currentSettings['orcid']['client_id'] = trim($_POST['orcid_client_id']);
        }
        if (!empty($_POST['oai_admin_email'])) {
            $currentSettings['oai_pmh']['admin_email'] = trim($_POST['oai_admin_email']);
        }

        if (saveInteropSettings($currentSettings)) {
            $actionMsg = 'Interoperability settings updated successfully.';
            log_audit(
                $pdo,
                'interoperability_updated',
                'system',
                0,
                'Administrator updated institutional interoperability & external connectors configuration',
                $adminId
            );
        } else {
            $errorMsg = 'Failed to persist interoperability settings.';
        }
    }
}

$settings = getInteropSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Interoperability & Connectors — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .connector-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .connector-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .tag-active { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .tag-dep { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
    </style>
</head>
<body>
    <div class="dash-navbar">
        <a href="dashboard.php" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Interoperability & Integrations</div>
            </div>
        </a>
        <div class="dash-user-controls">
            <a href="dashboard.php" class="btn-logout" style="margin-right: 0.5rem;">Dashboard</a>
            <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
        </div>
    </div>

    <main class="dash-container">
        <div style="margin-bottom: 1.5rem;">
            <h1 style="font-size: 1.75rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.25rem;">
                🌐 Institutional Interoperability & External Gateways
            </h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">
                Architecture, endpoints and credential configuration for campus Single Sign-On, ORCID synchronization, OAI-PMH harvesting and external repository syndication.
            </p>
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

        <form method="POST" action="interoperability.php">
            <?= csrf_field(); ?>

            <!-- 1. SAML 2.0 / Shibboleth Single Sign-On -->
            <div class="connector-card">
                <div class="connector-header">
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem;">
                            🏛️ University Single Sign-On (SAML 2.0 / Shibboleth)
                        </h3>
                        <span class="tag-dep"><?= e($settings['saml_sso']['status']); ?></span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="saml_sso_enabled" value="1" <?= $settings['saml_sso']['enabled'] ? 'checked' : ''; ?>>
                        Enable SSO Gateway
                    </label>
                </div>
                <div style="font-size: 0.875rem; color: var(--text-muted); line-height: 1.5;">
                    <div>SP Entity ID: <code><?= e($settings['saml_sso']['entity_id']); ?></code></div>
                    <div>IdP Metadata URL: <code><?= e($settings['saml_sso']['idp_metadata']); ?></code></div>
                    <div>Redirect Endpoint: <code><?= e($settings['saml_sso']['sso_endpoint']); ?></code></div>
                </div>
            </div>

            <!-- 2. ORCID Synchronization -->
            <div class="connector-card">
                <div class="connector-header">
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem;">
                            🆔 ORCID Researcher Identification & Sync
                        </h3>
                        <span class="tag-dep"><?= e($settings['orcid']['status']); ?></span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="orcid_enabled" value="1" <?= $settings['orcid']['enabled'] ? 'checked' : ''; ?>>
                        Enable ORCID Sync
                    </label>
                </div>
                <div class="form-group" style="margin-top: 0.75rem;">
                    <label for="orcid_client_id" style="font-size: 0.875rem;">ORCID API Client ID:</label>
                    <input type="text" name="orcid_client_id" id="orcid_client_id" class="form-control" 
                           value="<?= e($settings['orcid']['client_id'] ?? ''); ?>" placeholder="e.g. APP-XXXXXXXXXXXXXXXX" style="max-width: 400px;">
                    <small class="text-muted">Target Endpoint: <code><?= e($settings['orcid']['api_endpoint']); ?></code></small>
                </div>
            </div>

            <!-- 3. OAI-PMH Metadata Harvester -->
            <div class="connector-card">
                <div class="connector-header">
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem;">
                            📡 OAI-PMH 2.0 Repository Metadata Provider
                        </h3>
                        <span class="tag-active"><?= e($settings['oai_pmh']['status']); ?></span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="oai_pmh_enabled" value="1" <?= $settings['oai_pmh']['enabled'] ? 'checked' : ''; ?>>
                        Enable OAI-PMH Harvester
                    </label>
                </div>
                <div style="font-size: 0.875rem; color: var(--text-muted); line-height: 1.5;">
                    <div>OAI Base URL: <code><?= e($settings['oai_pmh']['base_url']); ?></code></div>
                    <div style="margin-top: 0.5rem;">
                        <label for="oai_admin_email" style="font-weight: 600;">Repository Curator Email:</label>
                        <input type="email" name="oai_admin_email" id="oai_admin_email" class="form-control" 
                               value="<?= e($settings['oai_pmh']['admin_email'] ?? ''); ?>" style="max-width: 350px; margin-top: 0.25rem;">
                    </div>
                </div>
            </div>

            <!-- 4. Library Systems Integration (Alma / Koha) -->
            <div class="connector-card">
                <div class="connector-header">
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem;">
                            📚 Library Management Systems (Ex Libris Alma / Koha)
                        </h3>
                        <span class="tag-dep"><?= e($settings['library_systems']['status']); ?></span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="library_systems_enabled" value="1" <?= $settings['library_systems']['enabled'] ? 'checked' : ''; ?>>
                        Enable Library Connector
                    </label>
                </div>
                <div style="font-size: 0.875rem; color: var(--text-muted);">
                    Target API: <code><?= e($settings['library_systems']['api_endpoint']); ?></code>
                </div>
            </div>

            <!-- 5. External Data Repositories (Zenodo / Dryad / Dataverse) -->
            <div class="connector-card">
                <div class="connector-header">
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 0.25rem;">
                            🌍 External Repositories Gateway (Zenodo / Dataverse / Dryad)
                        </h3>
                        <span class="tag-dep"><?= e($settings['external_repositories']['status']); ?></span>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                        <input type="checkbox" name="external_repositories_enabled" value="1" <?= $settings['external_repositories']['enabled'] ? 'checked' : ''; ?>>
                        Enable External Deposition
                    </label>
                </div>
                <div style="font-size: 0.875rem; color: var(--text-muted);">
                    Zenodo Deposition API: <code><?= e($settings['external_repositories']['zenodo_endpoint']); ?></code>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-bottom: 2rem;">
                <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700;">
                    💾 Save Interoperability Settings
                </button>
            </div>
        </form>
    </main>
</body>
</html>
