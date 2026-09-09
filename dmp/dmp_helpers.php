<?php
/**
 * Data Management Plan (DMP) Helper Functions
 * RDM Information System - Step 6
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';

/**
 * Validate that all 12 DMP sections are filled before submission
 * 
 * @param array $data
 * @return array Array of validation error messages (empty if completely valid)
 */
function validateDmpCompleteness(array $data): array {
    $errors = [];

    $sections = [
        'data_description'        => 'Section A: Data Description (What data will be collected/generated and its purpose)',
        'data_type'               => 'Section B: Data Type (Format and nature of research data)',
        'expected_volume'         => 'Section C: Expected Data Volume (Storage capacity required)',
        'data_organisation'       => 'Section D: Data Organization (Naming conventions, structuring and documentation)',
        'storage_location'        => 'Section E: Storage Location (Physical or cloud repositories during research)',
        'protection_measures'     => 'Section F: Protection Measures (Encryption, passwords and security controls)',
        'access_control'          => 'Section G: Access Control (Permissions, roles and authorization mechanisms)',
        'sensitive_data_handling' => 'Section H: Sensitive Data Handling (Confidential, personal, or ethical protocols)',
        'sharing_plan'            => 'Section I: Data Sharing Plan (Access terms, licenses and sharing conditions)',
        'retention_period'        => 'Section J: Retention Period (Duration research data will be kept)',
        'preservation_plan'       => 'Section K: Digital Preservation Plan (Long-term curation and persistent formats)',
        'disposal_plan'           => 'Section L: Secure Disposal Plan (Secure deletion protocols after retention expiry)'
    ];

    foreach ($sections as $field => $label) {
        $val = trim($data[$field] ?? '');
        if (empty($val)) {
            $errors[] = "Missing {$label}";
        }
    }

    return $errors;
}

/**
 * Generate formatted HTML badge for DMP status
 */
function getDmpStatusBadge(string $status): string {
    switch (strtolower(trim($status))) {
        case 'submitted':
            return '<span class="status-badge status-submitted" style="background-color: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;">Submitted for Review</span>';
        case 'under_review':
            return '<span class="status-badge status-review" style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a;">Under Review</span>';
        case 'approved':
            return '<span class="status-badge status-approved" style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0;">Approved</span>';
        case 'rejected':
            return '<span class="status-badge status-rejected" style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">Rejected / Needs Revision</span>';
        case 'draft':
        default:
            return '<span class="status-badge status-draft" style="background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;">Draft (In Progress)</span>';
    }
}

/**
 * Check if user can edit a DMP based on their project role and the DMP lifecycle state
 */
function canEditDmp(?string $projectRole, ?string $systemRole, string $dmpStatus): bool {
    $canManage = canManageMembers($projectRole, $systemRole); // true for owner, manager, admin
    if (!$canManage) {
        return false;
    }
    // Only draft or rejected DMPs can be edited by researchers/managers
    $status = strtolower(trim($dmpStatus));
    return in_array($status, ['draft', 'rejected'], true);
}

/**
 * Check if user can submit a DMP for review
 */
function canSubmitDmp(?string $projectRole, ?string $systemRole, string $dmpStatus): bool {
    $canManage = canManageMembers($projectRole, $systemRole); // true for owner, manager, admin
    if (!$canManage) {
        return false;
    }
    $status = strtolower(trim($dmpStatus));
    return in_array($status, ['draft', 'rejected'], true);
}
