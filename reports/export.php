<?php
/**
 * Multi-Format Report Export & Print Center (PDF/Print, CSV, TXT, JSON)
 * FUD RDM System - Phase 10: Institutional Reports & Analytics
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
require_once __DIR__ . '/reports_helpers.php';

// Allow all authenticated system roles (scoped server-side by role)
requireAuth();

$user       = currentUser();
$userId     = (int)$user['id'];
$systemRole = strtolower($user['role'] ?? 'researcher');

$reportType = strtolower(trim($_GET['report'] ?? 'projects'));
$format     = strtolower(trim($_GET['format'] ?? 'csv'));

// Extract Filter Parameters
$filters = [
    'department'    => trim($_GET['department'] ?? ''),
    'programme'     => trim($_GET['programme'] ?? ''),
    'session'       => trim($_GET['session'] ?? ''),
    'status'        => trim($_GET['status'] ?? ''),
    'date_preset'   => trim($_GET['date_preset'] ?? 'all'),
    'start_date'    => trim($_GET['start_date'] ?? ''),
    'end_date'      => trim($_GET['end_date'] ?? ''),
    'supervisor_id' => (int)($_GET['supervisor_id'] ?? 0)
];

// Fetch Role-Based Scope & Filtered Dataset
$scope      = getReportingScope($userId, $systemRole);
$reportData = getFilteredReportData($pdo, $reportType, $filters, $scope);

$reportTitle = $reportData['report_title'];
$headers     = $reportData['headers'];
$rows        = $reportData['rows'];
$timestamp   = date('Ymd_His');

// Build Active Filter Display Tags for Header/Print
$activeFilterTags = [];
if (!empty($filters['department'])) $activeFilterTags[] = "Department: " . e($filters['department']);
if (!empty($filters['session']))    $activeFilterTags[] = "Session: " . e($filters['session']);
if (!empty($filters['status']) && $filters['status'] !== 'all') $activeFilterTags[] = "Status: " . e(ucfirst($filters['status']));
if (!empty($filters['date_preset']) && $filters['date_preset'] !== 'all') $activeFilterTags[] = "Date Filter: " . e($filters['date_preset']);
if (!empty($filters['start_date'])) $activeFilterTags[] = "From: " . e($filters['start_date']);
if (!empty($filters['end_date']))   $activeFilterTags[] = "To: " . e($filters['end_date']);

// 1. PRINT / PDF FORMAT
if ($format === 'print' || $format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Official Report: <?= e($reportTitle); ?> — FUD RDM System</title>
        <style>
            @media print {
                @page { margin: 1.5cm; size: A4 landscape; }
                body { background: #ffffff !important; padding: 0 !important; font-size: 10pt; }
                .no-print { display: none !important; }
                .report-table th { background-color: #1e3a8a !important; color: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
            body {
                font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
                color: #0f172a;
                background: #f8fafc;
                margin: 0;
                padding: 2rem;
                font-size: 12px;
                line-height: 1.5;
            }
            .print-container {
                max-width: 1100px;
                margin: 0 auto;
                background: #ffffff;
                padding: 2.5rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            }
            .print-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-bottom: 3px solid #1e3a8a;
                padding-bottom: 1.25rem;
                margin-bottom: 1.5rem;
            }
            .institution-title {
                font-size: 1.5rem;
                font-weight: 800;
                color: #1e3a8a;
                margin: 0;
                text-transform: uppercase;
                letter-spacing: -0.5px;
            }
            .institution-sub {
                font-size: 0.95rem;
                color: #059669;
                font-weight: 700;
                margin: 0.2rem 0 0 0;
            }
            .filter-banner {
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                padding: 0.75rem 1rem;
                margin-bottom: 1.5rem;
                font-size: 0.85rem;
            }
            .filter-tag {
                display: inline-block;
                background: #e2e8f0;
                color: #334155;
                padding: 0.15rem 0.5rem;
                border-radius: 4px;
                font-weight: 700;
                margin-right: 0.35rem;
                margin-top: 0.2rem;
            }
            .report-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 2rem;
                font-size: 11px;
            }
            .report-table th {
                background: #1e3a8a;
                color: #ffffff;
                text-align: left;
                padding: 8px 10px;
                font-weight: 700;
                text-transform: uppercase;
                font-size: 10px;
            }
            .report-table td {
                padding: 8px 10px;
                border-bottom: 1px solid #e2e8f0;
            }
            .report-table tr:nth-child(even) td {
                background: #f8fafc;
            }
            .print-footer {
                margin-top: 2rem;
                padding-top: 1rem;
                border-top: 1px solid #e2e8f0;
                display: flex;
                justify-content: space-between;
                font-size: 0.75rem;
                color: #64748b;
            }
        </style>
    </head>
    <body>
        <div class="print-container">
            <div class="no-print" style="margin-bottom: 1.5rem; text-align: right;">
                <button onclick="window.print()" style="background: #1e3a8a; color: #ffffff; border: none; padding: 0.6rem 1.25rem; font-weight: 700; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">
                    🖨️ Click to Print / Save PDF
                </button>
            </div>

            <div class="print-header">
                <div>
                    <h1 class="institution-title">Federal University Dutse</h1>
                    <div class="institution-sub">Research Data Management & Digital Repository System</div>
                </div>
                <div style="text-align: right; font-size: 0.85rem; color: #475569;">
                    <strong>Date Generated:</strong> <?= date('F d, Y H:i:s'); ?><br>
                    <strong>Generated By:</strong> <?= e($user['first_name'] . ' ' . $user['last_name']); ?> (<?= e(ucfirst($systemRole)); ?>)<br>
                    <strong>Total Records:</strong> <?= count($rows); ?>
                </div>
            </div>

            <h2 style="font-size: 1.25rem; color: #0f172a; margin-bottom: 0.75rem; text-transform: uppercase; font-weight: 800; display: flex; align-items: center; justify-content: space-between;">
                <span>📊 <?= e($reportTitle); ?></span>
            </h2>

            <?php if (!empty($activeFilterTags)): ?>
                <div class="filter-banner">
                    <strong>Applied Report Filters:</strong>
                    <?php foreach ($activeFilterTags as $tag): ?>
                        <span class="filter-tag"><?= $tag; ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="filter-banner" style="color: #64748b;">
                    <strong>Scope:</strong> Full Institutional Overview (All Departments & Sessions)
                </div>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <div style="padding: 3rem; text-align: center; color: #64748b; font-size: 1rem; border: 1px dashed #cbd5e1; border-radius: 6px;">
                    No records found matching the specified report criteria.
                </div>
            <?php else: ?>
                <table class="report-table">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $h): ?>
                                <th><?= e($h); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <?php foreach ((array)$row as $val): ?>
                                    <td><?= e($val); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="print-footer">
                <div>&copy; <?= date('Y'); ?> Federal University Dutse &bull; Research Data System</div>
                <div>Official Confidential Institutional Document</div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. TEXT FORMAT
if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $reportType)) . '_report_' . $timestamp . '.txt"');

    echo "=========================================================================\n";
    echo "FEDERAL UNIVERSITY DUTSE - RESEARCH DATA MANAGEMENT SYSTEM\n";
    echo "OFFICIAL REPORT: " . strtoupper($reportTitle) . "\n";
    echo "Generated Date: " . date('F d, Y H:i:s') . "\n";
    echo "Generated By: " . $user['first_name'] . " " . $user['last_name'] . " (" . ucfirst($systemRole) . ")\n";
    echo "Total Records: " . count($rows) . "\n";
    if (!empty($activeFilterTags)) {
        echo "Filters: " . implode(', ', $activeFilterTags) . "\n";
    }
    echo "=========================================================================\n\n";

    echo implode("\t", $headers) . "\n";
    echo str_repeat("-", 80) . "\n";

    foreach ($rows as $row) {
        $rowVals = array_map(fn($v) => str_replace(["\r", "\n", "\t"], ' ', (string)$v), array_values((array)$row));
        echo implode("\t", $rowVals) . "\n";
    }
    exit;
}

// 3. JSON FORMAT
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $reportType)) . '_report_' . $timestamp . '.json"');

    echo json_encode([
        'institution'    => 'Federal University Dutse',
        'system'         => 'FUD RDM & Repository System',
        'report_type'    => $reportType,
        'report_title'   => $reportTitle,
        'generated_at'   => date('c'),
        'generated_by'   => $user['first_name'] . ' ' . $user['last_name'],
        'filters'        => $filters,
        'total_records'  => count($rows),
        'headers'        => $headers,
        'data'           => $rows
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// 4. CSV STREAM EXPORT (DEFAULT)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $reportType)) . '_report_' . $timestamp . '.csv"');

$output = fopen('php://output', 'w');

// Write BOM for UTF-8 Excel compatibility
fputs($output, "\xEF\xBB\xBF");

// Header info block inside CSV
fputcsv($output, ['Federal University Dutse - Research Data Management System']);
fputcsv($output, ['Report Title:', $reportTitle]);
fputcsv($output, ['Generated At:', date('Y-m-d H:i:s')]);
fputcsv($output, ['Generated By:', $user['first_name'] . ' ' . $user['last_name'] . ' (' . ucfirst($systemRole) . ')']);
if (!empty($activeFilterTags)) {
    fputcsv($output, ['Active Filters:', implode(', ', $activeFilterTags)]);
}
fputcsv($output, []); // Blank separator line

// Write Data Headers
fputcsv($output, array_map('escapeCsvValue', $headers));

// Write Data Rows
foreach ($rows as $row) {
    $escapedRow = array_map('escapeCsvValue', array_values((array)$row));
    fputcsv($output, $escapedRow);
}

fclose($output);
exit;
