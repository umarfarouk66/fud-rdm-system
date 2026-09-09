<?php
/**
 * Safe Application Backup & Disaster Recovery Helpers
 * RDM Information System
 * 
 * NOTE: Distinguishes between:
 * - DIGITAL PRESERVATION: Verifies bit-level integrity (SHA-256) and curation of research assets.
 * - BACKUP & RECOVERY: Snapshot generation and storage replication for disaster recovery.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

define('BACKUP_STORAGE_DIR', __DIR__ . '/../storage/backups');
define('BACKUP_RECORDS_FILE', __DIR__ . '/../config/backup_records.json');

/**
 * Ensure backup directory exists with restricted access
 */
function ensureBackupDirectory(): string {
    $dir = BACKUP_STORAGE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // Write .htaccess to prevent direct web download if on Apache
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }
    return $dir;
}

/**
 * Retrieve all historical backup records
 */
function getBackupRecords(): array {
    if (!file_exists(BACKUP_RECORDS_FILE)) {
        return [];
    }
    $raw = @file_get_contents(BACKUP_RECORDS_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Save backup records to log file
 */
function saveBackupRecords(array $records): bool {
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return (bool)@file_put_contents(BACKUP_RECORDS_FILE, $json);
}

/**
 * Generate a safe application-level database dump using PDO table iteration (pure PHP, zero shell execution)
 */
function createDatabaseSnapshot(PDO $pdo, int $adminUserId): array {
    ensureBackupDirectory();
    $timestamp = date('Ymd_His');
    $filename = "rdm_db_backup_{$timestamp}.sql";
    $filepath = BACKUP_STORAGE_DIR . '/' . $filename;

    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return ['success' => false, 'error' => 'Unable to open backup file for writing.'];
        }

        fwrite($handle, "-- ========================================================\n");
        fwrite($handle, "-- RDM System Database Backup Snapshot\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Performer: User #" . $adminUserId . "\n");
        fwrite($handle, "-- ========================================================\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            // Write Create Table structure
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($handle, $createRow[1] . ";\n\n");

            // Write Table Data Rows
            $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
            while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_keys($row);
                $escapedCols = array_map(function($c) { return "`$c`"; }, $cols);
                $escapedVals = array_map(function($val) use ($pdo) {
                    if ($val === null) return 'NULL';
                    return $pdo->quote($val);
                }, array_values($row));

                $line = "INSERT INTO `{$table}` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $escapedVals) . ");\n";
                fwrite($handle, $line);
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        $fileSize = filesize($filepath);
        $sha256 = hash_file('sha256', $filepath);

        $record = [
            'id'           => 'BKP-' . $timestamp,
            'type'         => 'database_snapshot',
            'filename'     => $filename,
            'file_size'    => $fileSize,
            'checksum'     => $sha256,
            'status'       => 'verified',
            'created_at'   => date('Y-m-d H:i:s'),
            'created_by'   => $adminUserId,
            'tables_count' => count($tables)
        ];

        $records = getBackupRecords();
        array_unshift($records, $record);
        saveBackupRecords($records);

        log_audit(
            $pdo,
            'backup_created',
            'system',
            0,
            "Created database backup snapshot '{$filename}' (Size: {$fileSize} bytes, SHA-256: {$sha256})",
            $adminUserId
        );

        return ['success' => true, 'record' => $record];

    } catch (Exception $e) {
        error_log("Database Snapshot Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Verify physical presence and SHA-256 integrity of a backup snapshot
 */
function verifyBackupSnapshot(string $backupId): array {
    $records = getBackupRecords();
    foreach ($records as &$rec) {
        if ($rec['id'] === $backupId) {
            $path = BACKUP_STORAGE_DIR . '/' . $rec['filename'];
            if (!file_exists($path)) {
                $rec['status'] = 'missing_file';
                saveBackupRecords($records);
                return ['valid' => false, 'error' => 'Physical snapshot file missing from backup storage.'];
            }
            $currentHash = hash_file('sha256', $path);
            if ($currentHash !== $rec['checksum']) {
                $rec['status'] = 'corrupted';
                saveBackupRecords($records);
                return ['valid' => false, 'error' => 'Checksum mismatch detected (possible file corruption).'];
            }
            $rec['status'] = 'verified';
            $rec['last_verified_at'] = date('Y-m-d H:i:s');
            saveBackupRecords($records);
            return ['valid' => true, 'record' => $rec];
        }
    }
    return ['valid' => false, 'error' => 'Backup record not found.'];
}
