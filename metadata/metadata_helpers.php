<?php
/**
 * Dataset Metadata Helper Functions
 * RDM Information System - Step 8
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';

// Standard licensing options
define('METADATA_LICENSE_OPTIONS', [
    'Creative Commons Attribution (CC BY 4.0)'                           => 'Creative Commons Attribution (CC BY 4.0)',
    'Creative Commons Attribution-NonCommercial (CC BY-NC 4.0)'          => 'Creative Commons Attribution-NonCommercial (CC BY-NC 4.0)',
    'Creative Commons Attribution-ShareAlike (CC BY-SA 4.0)'             => 'Creative Commons Attribution-ShareAlike (CC BY-SA 4.0)',
    'Creative Commons Zero (CC0 1.0 Public Domain Dedication)'           => 'Creative Commons Zero (CC0 1.0 Public Domain Dedication)',
    'Open Data Commons Open Database License (ODbL)'                     => 'Open Data Commons Open Database License (ODbL)',
    'Academic Research Non-Commercial License'                           => 'Academic Research Non-Commercial License',
    'All Rights Reserved (Restricted Institutional Access)'              => 'All Rights Reserved (Restricted Institutional Access)',
    'Custom / Other License'                                             => 'Custom / Other License'
]);

/**
 * Calculate metadata completeness percentage, count and qualitative status
 * 
 * @param array $metadata
 * @return array ['percentage' => int, 'completed' => int, 'total' => int, 'status' => string, 'badge_class' => string]
 */
function calculateMetadataCompleteness(array $metadata): array {
    $requiredFields = [
        'creator',
        'keywords',
        'subject_area',
        'creation_date',
        'collection_methodology',
        'geographic_coverage',
        'data_type',
        'file_format',
        'license',
        'access_conditions',
        'processing_documentation'
    ];

    $total = count($requiredFields);
    $completed = 0;

    foreach ($requiredFields as $field) {
        $val = trim((string)($metadata[$field] ?? ''));
        if (!empty($val)) {
            $completed++;
        }
    }

    $percentage = (int)round(($completed / $total) * 100);

    if ($percentage >= 100) {
        $status = 'Complete';
        $badgeClass = 'badge-complete';
    } elseif ($percentage >= 70) {
        $status = 'Mostly Complete';
        $badgeClass = 'badge-mostly-complete';
    } else {
        $status = 'Incomplete';
        $badgeClass = 'badge-incomplete';
    }

    return [
        'percentage'  => $percentage,
        'completed'   => $completed,
        'total'       => $total,
        'status'      => $status,
        'badge_class' => $badgeClass
    ];
}

/**
 * Validate metadata form inputs
 * 
 * @param array $data
 * @return array Array of validation error messages
 */
function validateMetadataForm(array $data): array {
    $errors = [];

    // Required text inputs
    $requiredLabels = [
        'creator'                  => 'Creator / Primary Investigator',
        'keywords'                 => 'Keywords (at least one keyword)',
        'subject_area'             => 'Subject Area / Domain',
        'creation_date'            => 'Creation / Collection Date',
        'collection_methodology'   => 'Collection Methodology',
        'geographic_coverage'      => 'Geographic Coverage',
        'data_type'                => 'Data Type',
        'file_format'              => 'File Format',
        'license'                  => 'License',
        'access_conditions'        => 'Access Conditions',
        'processing_documentation' => 'Processing & Provenance Documentation'
    ];

    foreach ($requiredLabels as $field => $label) {
        $val = trim((string)($data[$field] ?? ''));
        if (empty($val)) {
            $errors[] = "Missing required field: {$label}";
        }
    }

    // Validate creation_date format
    $creationDate = trim((string)($data['creation_date'] ?? ''));
    if (!empty($creationDate) && !strtotime($creationDate)) {
        $errors[] = 'Creation Date is not a valid date format (YYYY-MM-DD).';
    }

    // Validate modification_date if provided
    $modificationDate = trim((string)($data['modification_date'] ?? ''));
    if (!empty($modificationDate)) {
        if (!strtotime($modificationDate)) {
            $errors[] = 'Modification Date is not a valid date format (YYYY-MM-DD).';
        } elseif (!empty($creationDate) && strtotime($modificationDate) < strtotime($creationDate)) {
            $errors[] = 'Modification Date cannot be earlier than the Creation Date.';
        }
    }

    // Maximum field length checks
    if (!empty($data['creator']) && strlen($data['creator']) > 255) {
        $errors[] = 'Creator field exceeds 255 characters.';
    }
    if (!empty($data['subject_area']) && strlen($data['subject_area']) > 255) {
        $errors[] = 'Subject Area exceeds 255 characters.';
    }
    if (!empty($data['geographic_coverage']) && strlen($data['geographic_coverage']) > 255) {
        $errors[] = 'Geographic Coverage exceeds 255 characters.';
    }
    if (!empty($data['file_format']) && strlen($data['file_format']) > 100) {
        $errors[] = 'File Format exceeds 100 characters.';
    }
    if (!empty($data['license']) && strlen($data['license']) > 255) {
        $errors[] = 'License field exceeds 255 characters.';
    }

    return $errors;
}

/**
 * Check if user can create or edit metadata for a dataset
 */
function canManageDatasetMetadata(?string $projectRole, ?string $systemRole, int $datasetOwnerId, int $userId): bool {
    if ($userId === $datasetOwnerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    return $projectRole && in_array($projectRole, ['owner', 'manager'], true);
}
