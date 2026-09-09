<?php
/**
 * Dataset Identifiers & Academic Citations Helpers
 * RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

/**
 * Retrieve persistent identifiers for a dataset
 */
function getDatasetIdentifiers(PDO $pdo, int $datasetId): array {
    if ($datasetId <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM dataset_identifiers 
            WHERE dataset_id = :dataset_id 
            ORDER BY id ASC
        ");
        $stmt->execute([':dataset_id' => $datasetId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch Dataset Identifiers Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Add or update a persistent identifier for a dataset
 */
function setDatasetIdentifier(PDO $pdo, int $datasetId, string $type, string $value): bool {
    $type = trim($type);
    $value = trim($value);
    if ($datasetId <= 0 || empty($type) || empty($value)) {
        return false;
    }

    try {
        $chk = $pdo->prepare("
            SELECT id FROM dataset_identifiers 
            WHERE dataset_id = :dataset_id AND identifier_type = :type 
            LIMIT 1
        ");
        $chk->execute([':dataset_id' => $datasetId, ':type' => $type]);
        $existingId = $chk->fetchColumn();

        if ($existingId) {
            $stmt = $pdo->prepare("
                UPDATE dataset_identifiers 
                SET identifier_value = :val 
                WHERE id = :id
            ");
            return $stmt->execute([':val' => $value, ':id' => $existingId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO dataset_identifiers (dataset_id, identifier_type, identifier_value)
                VALUES (:dataset_id, :type, :val)
            ");
            return $stmt->execute([
                ':dataset_id' => $datasetId,
                ':type'       => $type,
                ':val'        => $value
            ]);
        }
    } catch (PDOException $e) {
        error_log("Set Dataset Identifier Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate standard academic citations for a dataset across major referencing styles
 * (APA 7th, BibTeX, Chicago 17th, MLA 9th, Harvard, IEEE)
 */
function generateDatasetCitations(array $dataset, ?array $metadata = null, array $identifiers = []): array {
    $title = trim($dataset['title'] ?? 'Untitled Research Dataset');
    $year  = !empty($dataset['published_at']) 
        ? date('Y', strtotime($dataset['published_at'])) 
        : date('Y', strtotime($dataset['created_at'] ?? 'now'));

    // Creator / Author resolution
    $creator = trim($metadata['creator'] ?? '');
    if (empty($creator)) {
        $creator = trim(($dataset['owner_first_name'] ?? '') . ' ' . ($dataset['owner_last_name'] ?? ''));
    }
    if (empty($creator)) {
        $creator = 'Research Team';
    }

    // Publisher / Institution
    $publisher = trim($metadata['publisher'] ?? 'Institutional Research Data Repository');
    $version = (int)($dataset['current_version'] ?? 1);
    
    // Resolve primary identifier (DOI preferred, then Handle/ARK/Institutional)
    $primaryPid = '';
    $doiValue = '';
    foreach ($identifiers as $idRow) {
        $t = strtoupper($idRow['identifier_type'] ?? '');
        $v = trim($idRow['identifier_value'] ?? '');
        if ($t === 'DOI') {
            $doiValue = $v;
            $primaryPid = "https://doi.org/{$v}";
            break;
        } elseif (empty($primaryPid) && !empty($v)) {
            $primaryPid = $v;
        }
    }

    if (empty($primaryPid)) {
        $primaryPid = "RDM-PID-" . str_pad($dataset['id'] ?? '1', 6, '0', STR_PAD_LEFT);
    }

    $citations = [];

    // 1. APA 7th Edition
    $citations['apa'] = [
        'style' => 'APA (7th Edition)',
        'text'  => "{$creator} ({$year}). {$title} (Version {$version}) [Data set]. {$publisher}. {$primaryPid}"
    ];

    // 2. BibTeX Format
    $bibtexKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $creator)[0] ?? 'dataset')) . "{$year}_{$version}";
    $citations['bibtex'] = [
        'style' => 'BibTeX',
        'text'  => "@dataset{". $bibtexKey . ",\n" .
                   "  author    = {" . addslashes($creator) . "},\n" .
                   "  title     = {{" . addslashes($title) . "}},\n" .
                   "  year      = {" . $year . "},\n" .
                   "  version   = {" . $version . "},\n" .
                   "  publisher = {" . addslashes($publisher) . "},\n" .
                   (!empty($doiValue) ? "  doi       = {" . addslashes($doiValue) . "},\n" : "") .
                   "  url       = {" . addslashes($primaryPid) . "}\n" .
                   "}"
    ];

    // 3. Chicago 17th Edition (Author-Date)
    $citations['chicago'] = [
        'style' => 'Chicago (17th Edition)',
        'text'  => "{$creator}. {$year}. \"{$title}.\" Version {$version}. {$publisher}. {$primaryPid}."
    ];

    // 4. MLA 9th Edition
    $citations['mla'] = [
        'style' => 'MLA (9th Edition)',
        'text'  => "{$creator}. {$title}. Version {$version}, {$publisher}, {$year}, {$primaryPid}."
    ];

    // 5. Harvard Referencing
    $citations['harvard'] = [
        'style' => 'Harvard',
        'text'  => "{$creator}, {$year}. {$title}, Version {$version}, {$publisher}, Available at: <{$primaryPid}>."
    ];

    // 6. IEEE Referencing
    $citations['ieee'] = [
        'style' => 'IEEE',
        'text'  => "{$creator}, \"{$title},\" ver. {$version}, {$publisher}, {$year}. [Online]. Available: {$primaryPid}."
    ];

    return $citations;
}

/**
 * Fetch pre-stored citations or generate and persist standard citations
 */
function getOrGenerateDatasetCitations(PDO $pdo, int $datasetId, array $dataset, ?array $metadata = null): array {
    $identifiers = getDatasetIdentifiers($pdo, $datasetId);
    $generated = generateDatasetCitations($dataset, $metadata, $identifiers);

    try {
        // Check if database has custom pre-saved citations
        $stmt = $pdo->prepare("SELECT * FROM dataset_citations WHERE dataset_id = :id");
        $stmt->execute([':id' => $datasetId]);
        $saved = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($saved)) {
            foreach ($saved as $row) {
                $styleKey = strtolower(trim($row['citation_style'] ?? ''));
                if (!empty($styleKey) && isset($generated[$styleKey])) {
                    $generated[$styleKey]['text'] = $row['citation_text'];
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Get Stored Citations Error: " . $e->getMessage());
    }

    return $generated;
}
