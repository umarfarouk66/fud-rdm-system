<?php
/**
 * Dataset Management and Secure Upload Helpers
 * RDM Information System - Step 7
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../projects/project_auth.php';

// Allowed research data file extensions whitelist
define('ALLOWED_DATASET_EXTENSIONS', [
    'csv', 'tsv', 'xlsx', 'xls', 'json', 'xml', 'txt', 'pdf',
    'zip', 'tar', 'gz', 'sav', 'dta', 'rds', 'parquet',
    'fasta', 'fastq', 'vcf', 'nc', 'tif', 'tiff', 'h5', 'hdf5'
]);

// Maximum upload file size in bytes (100 MB default)
define('MAX_DATASET_UPLOAD_BYTES', 104857600);

/**
 * Validate an uploaded file for security, format whitelist and size limits
 * 
 * @param array $file $_FILES['input_name'] array
 * @param int $maxBytes
 * @return array ['valid' => bool, 'error' => string|null, 'extension' => string, 'mime' => string, 'size' => int, 'original_name' => string]
 */
function validateUploadedFile(array $file, int $maxBytes = MAX_DATASET_UPLOAD_BYTES): array {
    // 1. Check upload error
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['valid' => false, 'error' => 'Invalid file upload parameters.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['valid' => false, 'error' => 'No file was uploaded.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['valid' => false, 'error' => 'File exceeds maximum upload size limit.'];
        default:
            return ['valid' => false, 'error' => 'An unknown error occurred during file upload.'];
    }

    // 2. Check file size
    $fileSize = (int)$file['size'];
    if ($fileSize <= 0) {
        return ['valid' => false, 'error' => 'Uploaded file is empty (0 bytes).'];
    }
    if ($fileSize > $maxBytes) {
        $maxMB = round($maxBytes / (1024 * 1024));
        return ['valid' => false, 'error' => "File exceeds the {$maxMB} MB size limit."];
    }

    // 3. Extract and check extension
    $originalName = basename($file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (empty($extension)) {
        return ['valid' => false, 'error' => 'File has no extension. Please upload a recognized research data format.'];
    }

    if (!in_array($extension, ALLOWED_DATASET_EXTENSIONS, true)) {
        return ['valid' => false, 'error' => "File extension '.{$extension}' is not permitted. Allowed formats include: " . implode(', ', array_slice(ALLOWED_DATASET_EXTENSIONS, 0, 10)) . "..."];
    }

    // 4. Verify server-side MIME type
    $tempPath = $file['tmp_name'];
    if (!is_uploaded_file($tempPath)) {
        return ['valid' => false, 'error' => 'File upload security check failed.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tempPath);
    finfo_close($finfo);

    // Block executable or server-side scripts even if disguised
    $dangerousMimes = [
        'application/x-httpd-php', 'text/x-php', 'application/x-executable',
        'application/x-msdownload', 'application/x-sh', 'application/javascript'
    ];
    if (in_array(strtolower($mimeType), $dangerousMimes, true)) {
        return ['valid' => false, 'error' => 'Security rejection: Uploaded file contains executable or script content.'];
    }

    return [
        'valid'         => true,
        'error'         => null,
        'original_name' => $originalName,
        'extension'     => $extension,
        'mime'          => $mimeType ?: 'application/octet-stream',
        'size'          => $fileSize,
        'temp_path'     => $tempPath
    ];
}

/**
 * Move uploaded file into secure storage using a random filename and compute SHA-256
 * 
 * @param string $tempPath
 * @param string $extension
 * @return array ['stored_file_name' => string, 'file_path' => string, 'checksum' => string]
 */
function saveUploadedDatasetFile(string $tempPath, string $extension): array {
    $storageDir = __DIR__ . '/../storage/datasets';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    // Generate cryptographically random stored filename
    $randomName = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetFullPath = $storageDir . '/' . $randomName;
    $relativeFilePath = 'storage/datasets/' . $randomName;

    // Compute SHA-256 checksum before move
    $checksum = hash_file('sha256', $tempPath);

    if (is_uploaded_file($tempPath)) {
        $moved = move_uploaded_file($tempPath, $targetFullPath);
    } else {
        $moved = copy($tempPath, $targetFullPath);
        if ($moved && file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

    if (!$moved) {
        throw new Exception("Failed to move uploaded file to secure storage directory.");
    }

    return [
        'stored_file_name' => $randomName,
        'file_path'        => $relativeFilePath,
        'full_path'        => $targetFullPath,
        'checksum'         => $checksum
    ];
}

/**
 * Check if user can view dataset details and metadata
 */
function canViewDataset(?string $projectRole, ?string $systemRole, string $accessLevel, int $ownerId, int $userId): bool {
    if ($userId === $ownerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    if ($projectRole !== null) {
        return true;
    }
    $systemRole = strtolower($systemRole ?? '');
    if (in_array($systemRole, ['supervisor', 'librarian'], true)) {
        return true;
    }
    $lvl = strtolower(trim($accessLevel));
    // Public and Restricted datasets can be viewed (Restricted shows overview + Request Access option)
    return in_array($lvl, ['public', 'restricted'], true);
}

/**
 * Check if user can edit dataset metadata or upload new versions
 */
function canEditDataset(?string $projectRole, ?string $systemRole, int $ownerId, int $userId): bool {
    if ($userId === $ownerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    return $projectRole && in_array($projectRole, ['owner', 'manager'], true);
}

/**
 * Check if user can download dataset files (supports approved access requests)
 */
function canDownloadDataset(?string $projectRole, ?string $systemRole, string $accessLevel, int $ownerId, int $userId, ?PDO $pdo = null, int $datasetId = 0): bool {
    if ($userId === $ownerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    if ($projectRole !== null) {
        return true;
    }
    $lvl = strtolower(trim($accessLevel));
    if ($lvl === 'public') {
        return true;
    }
    if ($lvl === 'restricted' && $pdo !== null && $datasetId > 0) {
        require_once __DIR__ . '/../access/access_helpers.php';
        return hasApprovedAccessRequest($pdo, $datasetId, $userId);
    }
    return false;
}

/**
 * Check if user can delete the dataset
 */
function canDeleteDataset(?string $projectRole, ?string $systemRole, int $ownerId, int $userId): bool {
    if ($userId === $ownerId || strtolower($systemRole ?? '') === 'admin') {
        return true;
    }
    return $projectRole === 'owner';
}

/**
 * Record dataset access event in dataset_access_logs
 */
function log_dataset_access(PDO $pdo, int $datasetId, ?int $userId, string $action): bool {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Browser';

        $stmt = $pdo->prepare("
            INSERT INTO dataset_access_logs (
                dataset_id,
                user_id,
                action,
                ip_address,
                user_agent
            ) VALUES (
                :dataset_id,
                :user_id,
                :action,
                :ip_address,
                :user_agent
            )
        ");

        return $stmt->execute([
            ':dataset_id' => $datasetId,
            ':user_id'    => $userId ? (int)$userId : null,
            ':action'     => $action,
            ':ip_address' => substr($ipAddress, 0, 45),
            ':user_agent' => $userAgent
        ]);
    } catch (PDOException $e) {
        error_log("Dataset Access Log Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Format bytes into human-readable representation
 */
function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 0) {
        return $bytes . ' Bytes';
    } else {
        return '0 Bytes';
    }
}
