<?php
/**
 * Institutional Interoperability & External Integration Configuration
 * RDM Information System
 */

define('INTEROP_CONFIG_FILE', __DIR__ . '/interoperability_settings.json');

/**
 * Default Interoperability Connectors & Endpoints
 */
function getDefaultInteropSettings(): array {
    return [
        'saml_sso' => [
            'enabled'        => false,
            'name'           => 'University Single Sign-On (SAML 2.0 / Shibboleth)',
            'entity_id'      => 'https://rdm.institution.ac.uk/shibboleth',
            'idp_metadata'   => 'https://idp.institution.ac.uk/idp/shibboleth',
            'sso_endpoint'   => 'https://idp.institution.ac.uk/idp/profile/SAML2/Redirect/SSO',
            'status'         => 'Deployment Dependent (Requires Campus IdP Credentials)'
        ],
        'orcid' => [
            'enabled'        => false,
            'name'           => 'ORCID Researcher Identifier Synchronization',
            'client_id'      => '',
            'api_endpoint'   => 'https://pub.orcid.org/v3.0/',
            'status'         => 'Deployment Dependent (Requires ORCID API Client Secret)'
        ],
        'oai_pmh' => [
            'enabled'        => true,
            'name'           => 'OAI-PMH 2.0 Metadata Harvester Endpoint',
            'base_url'       => 'https://rdm.institution.ac.uk/oai-pmh',
            'repository_name'=> 'Institutional Research Data Repository (OAI Data Provider)',
            'admin_email'    => 'rdm-curator@institution.ac.uk',
            'status'         => 'Active (Local Endpoint Ready)'
        ],
        'library_systems' => [
            'enabled'        => false,
            'name'           => 'Library Management Systems (Ex Libris Alma / Koha)',
            'api_endpoint'   => 'https://api-eu.hosted.exlibrisgroup.com/almaws/v1/',
            'status'         => 'Deployment Dependent (Requires Alma API Key)'
        ],
        'external_repositories' => [
            'enabled'        => false,
            'name'           => 'External Repositories Gateway (Zenodo / Dataverse / Dryad)',
            'zenodo_endpoint'=> 'https://zenodo.org/api/deposit/depositions',
            'status'         => 'Deployment Dependent (Requires Access Token)'
        ]
    ];
}

/**
 * Load current interoperability configuration
 */
function getInteropSettings(): array {
    $defaults = getDefaultInteropSettings();
    if (!file_exists(INTEROP_CONFIG_FILE)) {
        return $defaults;
    }
    $raw = @file_get_contents(INTEROP_CONFIG_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_replace_recursive($defaults, $data);
}

/**
 * Save updated interoperability configuration
 */
function saveInteropSettings(array $settings): bool {
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return (bool)@file_put_contents(INTEROP_CONFIG_FILE, $json);
}
