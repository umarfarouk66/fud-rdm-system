<?php
/**
 * Data Preservation & Archiving Helpers
 * RDM Information System - Step 10
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';

// Standard logical preservation storage locations
define('PRESERVATION_LOCATIONS', [
    'Institutional Research Repository'    => 'Institutional Research Repository (Primary Tier)',
    'Long-Term Digital Archive'            => 'Long-Term Digital Archive (Secondary Tier)',
    'Cold Storage / Offline Archive'       => 'Cold Storage / Offline Archive (Disaster Recovery)',
    'Secure Academic Vault'                => 'Secure Academic Vault (High-Compliance Storage)'
]);

/**
 * Check if the user is authorized to manage dataset preservation and archival
 */
function canManagePreservation(?string $systemRole): bool {
    $role = strtolower(trim($systemRole ?? ''));
    return in_array($role, ['librarian', 'admin'], true);
}

/**
 * Resolve and validate dataset physical file path safely within storage/datasets/
 */
function resolveSecureDatasetPath(string $storedPath): ?string {
    $storageDir = realpath(__DIR__ . '/../storage/datasets');
    if (!$storageDir) {
        return null;
    }

    // Protect against path traversal
    if (strpos($storedPath, '..') !== false) {
        return null;
    }

    $fileName = basename($storedPath);
    $fullPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    if (!file_exists($fullPath) || !is_readable($fullPath)) {
        return null;
    }

    $realPath = realpath($fullPath);
    if (!$realPath || strpos($realPath, $storageDir) !== 0) {
        return null;
    }

    return $realPath;
}

/**
 * Verify physical file integrity by comparing computed SHA-256 and size against database
 */
function verifyPhysicalFileIntegrity(string $storedPath, string $expectedChecksum, int $expectedSize): array {
    $resolvedPath = resolveSecureDatasetPath($storedPath);

    if (!$resolvedPath) {
        return [
            'valid'           => false,
            'checksum_match'  => false,
            'size_match'      => false,
            'actual_checksum' => null,
            'actual_size'     => null,
            'error'           => 'Physical file is missing, unreadable, or storage path is invalid.',
            'resolved_path'   => null
        ];
    }

    $actualSize = filesize($resolvedPath);
    $actualChecksum = hash_file('sha256', $resolvedPath);

    $sizeMatch = ($actualSize === $expectedSize);
    $checksumMatch = (hash_equals(strtolower(trim($expectedChecksum)), strtolower(trim($actualChecksum))));

    $valid = ($sizeMatch && $checksumMatch);
    $error = null;

    if (!$sizeMatch) {
        $error = "File size mismatch (Expected {$expectedSize} bytes, found {$actualSize} bytes).";
    } elseif (!$checksumMatch) {
        $error = "Checksum mismatch (Expected {$expectedChecksum}, calculated {$actualChecksum}). File may have been altered or corrupted.";
    }

    return [
        'valid'           => $valid,
        'checksum_match'  => $checksumMatch,
        'size_match'      => $sizeMatch,
        'actual_checksum' => $actualChecksum,
        'actual_size'     => $actualSize,
        'error'           => $error,
        'resolved_path'   => $resolvedPath
    ];
}

/**
 * Encode structured preservation metadata into JSON for notes field
 */
function encodePreservationNotes(array $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Parse structured preservation metadata from notes field
 */
function parsePreservationNotes(?string $rawNotes): array {
    if (empty($rawNotes)) {
        return [];
    }

    $decoded = json_decode($rawNotes, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    // Fallback if plain text note
    return [
        'preservation_notes'  => $rawNotes,
        'location'            => 'Institutional Repository',
        'verification_status' => 'recorded'
    ];
}

/**
 * Check if a dataset is protected by an active preservation record or archived status
 */
function isDatasetPreservedOrArchived(PDO $pdo, int $datasetId): bool {
    try {
        // 1. Check datasets status
        $dStmt = $pdo->prepare("SELECT status FROM datasets WHERE id = :id LIMIT 1");
        $dStmt->execute([':id' => $datasetId]);
        $status = strtolower(trim($dStmt->fetchColumn() ?: ''));

        if (in_array($status, ['preserved', 'archived'], true)) {
            return true;
        }

        // 2. Check preservation_records table
        $pStmt = $pdo->prepare("SELECT id FROM preservation_records WHERE dataset_id = :id AND action IN ('preserve', 'archive') LIMIT 1");
        $pStmt->execute([':id' => $datasetId]);
        return (bool)$pStmt->fetch();

    } catch (PDOException $e) {
        error_log("Preservation Protection Check Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Render HTML status badge for preservation / dataset status
 */
function getPreservationStatusBadge(string $status): string {
    switch (strtolower(trim($status))) {
        case 'preserved':
            return '<span class="status-badge" style="background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Preserved</span>';
        case 'archived':
            return '<span class="status-badge" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Archived</span>';
        case 'failed':
            return '<span class="status-badge" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Integrity Failed</span>';
        case 'pending':
        default:
            return '<span class="status-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.25rem 0.65rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Awaiting Preservation</span>';
    }
}
