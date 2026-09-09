<?php
/**
 * Research Data Repository Search & Discovery Portal
 * RDM Information System - Step 11
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';
require_once __DIR__ . '/search_helpers.php';

// Allow public discovery without mandatory login
$isGuest = !isLoggedIn();
$user = $isGuest ? null : currentUser();
$userId = $user ? (int)$user['id'] : 0;
$systemRole = $user ? $user['role'] : 'guest';

$searchMode = strtolower(trim($_GET['mode'] ?? 'datasets'));
if ($searchMode !== 'projects') {
    $searchMode = 'datasets';
}

// Execute search query based on selected search mode
if ($searchMode === 'projects') {
    $searchData = executeProjectSearch($pdo, $userId, $systemRole, $_GET);
} else {
    $searchData = executeRepositorySearch($pdo, $userId, $systemRole, $_GET);
}

$results     = $searchData['results'];
$total       = $searchData['total'];
$page        = $searchData['page'];
$limit       = $searchData['limit'];
$totalPages  = $searchData['total_pages'];
$query       = $searchData['query'];
$currentSort = $searchData['sort'] ?? 'newest';

// Fetch available filter options
$filterOptions = getAvailableFilterOptions($pdo, $userId, $systemRole);

// Selected filter state
$selectedSubject      = trim($_GET['subject'] ?? '');
$selectedType         = trim($_GET['type'] ?? '');
$selectedFormat       = trim($_GET['format'] ?? '');
$selectedCoverage     = trim($_GET['coverage'] ?? '');
$selectedAccess       = strtolower(trim($_GET['access'] ?? ''));
$selectedPreservation = strtolower(trim($_GET['preservation'] ?? ''));

$hasActiveFilters = ($query !== '' || $selectedSubject !== '' || $selectedType !== '' || $selectedFormat !== '' || $selectedCoverage !== '' || $selectedAccess !== '' || $selectedPreservation !== '');

// Calculate showing range
$showingStart = ($total > 0) ? (($page - 1) * $limit) + 1 : 0;
$showingEnd   = min($total, $page * $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research Data Repository Search & Discovery — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2.1">
    <style>
        .search-hero {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 50%, #2563eb 100%);
            border-radius: var(--radius-lg);
            padding: 2.5rem 2rem;
            color: #ffffff;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }
        .search-hero h1 {
            font-size: 1.85rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .search-hero p {
            color: #bfdbfe;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        .search-input-group {
            display: flex;
            gap: 0.5rem;
            background: #ffffff;
            border-radius: var(--radius-md);
            padding: 0.35rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .search-input-group input[type="text"] {
            flex: 1;
            border: none;
            outline: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            color: var(--text-main);
            border-radius: var(--radius-sm);
        }
        .btn-search {
            background: var(--accent-color);
            color: #ffffff;
            border: none;
            padding: 0.75rem 1.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-search:hover {
            background: #1d4ed8;
        }
        .search-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 2rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .search-layout {
                grid-template-columns: 1fr;
            }
        }
        .filter-sidebar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .filter-group {
            margin-bottom: 1.25rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .filter-group:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .filter-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 0.4rem;
            display: block;
        }
        .filter-select {
            width: 100%;
            padding: 0.55rem 0.75rem;
            font-size: 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            background: #ffffff;
            color: var(--text-main);
        }
        .results-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        .result-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .result-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        .result-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        .result-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            text-decoration: none;
            line-height: 1.35;
        }
        .result-title:hover {
            color: var(--accent-color);
        }
        .result-abstract {
            font-size: 0.9rem;
            color: var(--text-main);
            line-height: 1.55;
            margin-bottom: 1rem;
        }
        .metadata-pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .meta-pill {
            font-size: 0.75rem;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .tag-pill {
            font-size: 0.75rem;
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-sm);
            background: #eff6ff;
            color: #1e40af;
            font-weight: 500;
        }
        .card-footer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }
        .btn-card-action {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.85rem;
            font-size: 0.825rem;
            font-weight: 600;
            border-radius: var(--radius-sm);
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            transition: all 0.15s ease;
        }
        .btn-card-action:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
            background: var(--primary-light);
        }
        .btn-card-primary {
            background-color: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .btn-card-primary:hover {
            background-color: var(--accent-color);
            color: #ffffff;
        }
        .btn-card-success {
            background-color: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        .btn-card-success:hover {
            background-color: #047857;
            color: #ffffff;
        }
        .btn-card-warning {
            background-color: #d97706;
            color: #ffffff;
            border-color: #d97706;
        }
        .btn-card-warning:hover {
            background-color: #b45309;
            color: #ffffff;
        }
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.4rem;
            margin-top: 2.5rem;
            margin-bottom: 2rem;
        }
        .page-link {
            padding: 0.5rem 0.85rem;
            font-size: 0.875rem;
            font-weight: 600;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            text-decoration: none;
            border-radius: var(--radius-sm);
        }
        .page-link:hover {
            border-color: var(--accent-color);
            color: var(--accent-color);
        }
        .page-link.active {
            background: var(--primary-color);
            color: #ffffff;
            border-color: var(--primary-color);
        }
        .empty-search-state {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 3.5rem 2rem;
            text-align: center;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="dash-navbar">
        <a href="<?= e(getDashboardUrl($systemRole, '../')); ?>" class="dash-brand">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="dash-brand-logo" style="height: 42px; max-height: 42px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="dash-brand-title">FUD RDM System</div>
                <div class="dash-brand-subtitle">Federal University Dutse</div>
            </div>
        </a>

        <div class="dash-user-controls">
            <?php if (!$isGuest && $user): ?>
                <div class="user-badge-container">
                    <div>
                        <div class="user-name"><?= e($user['name']); ?></div>
                        <div class="user-affiliation"><?= e($user['department'] ?: $user['institution']); ?></div>
                    </div>
                    <span class="role-badge role-badge-<?= e($systemRole); ?>"><?= e($systemRole); ?></span>
                </div>
                <a href="../auth/logout.php" class="btn-logout">Sign Out</a>
            <?php else: ?>
                <a href="../public/index.php" class="btn-logout" style="background: transparent; color: var(--primary-color);">Home</a>
                <a href="../public/login.php" class="btn-logout" style="background: var(--primary-color); color: #ffffff; margin-right: 0.35rem;">Sign In</a>
                <a href="../public/register.php" class="btn-logout" style="background: var(--accent-color); color: #ffffff;">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="dash-container">

        <!-- Search Hero Section -->
        <div class="search-hero">
            <h1>Institutional Research Repository Search & Discovery</h1>
            <p>Discover, explore and access research datasets, metadata and institutional projects at Federal University Dutse</p>

            <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;">
                <a href="<?= e(buildSearchUrl($_GET, ['mode' => 'datasets'])); ?>" style="padding: 0.45rem 1rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 700; text-decoration: none; background: <?= $searchMode === 'datasets' ? '#ffffff; color: #1e3a8a;' : 'rgba(255,255,255,0.2); color: #ffffff;'; ?>">
                    📊 Datasets &amp; Repository Files
                </a>
                <a href="<?= e(buildSearchUrl($_GET, ['mode' => 'projects'])); ?>" style="padding: 0.45rem 1rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 700; text-decoration: none; background: <?= $searchMode === 'projects' ? '#ffffff; color: #1e3a8a;' : 'rgba(255,255,255,0.2); color: #ffffff;'; ?>">
                    📁 Research Projects
                </a>
            </div>

            <form action="index.php" method="GET" autocomplete="off">
                <input type="hidden" name="mode" value="<?= e($searchMode); ?>">
                <!-- Preserve active filters on text query -->
                <?php if ($selectedSubject !== ''): ?><input type="hidden" name="subject" value="<?= e($selectedSubject); ?>"><?php endif; ?>
                <?php if ($selectedType !== ''): ?><input type="hidden" name="type" value="<?= e($selectedType); ?>"><?php endif; ?>
                <?php if ($selectedFormat !== ''): ?><input type="hidden" name="format" value="<?= e($selectedFormat); ?>"><?php endif; ?>
                <?php if ($selectedCoverage !== ''): ?><input type="hidden" name="coverage" value="<?= e($selectedCoverage); ?>"><?php endif; ?>
                <?php if ($selectedAccess !== ''): ?><input type="hidden" name="access" value="<?= e($selectedAccess); ?>"><?php endif; ?>
                <?php if ($selectedPreservation !== ''): ?><input type="hidden" name="preservation" value="<?= e($selectedPreservation); ?>"><?php endif; ?>
                <?php if ($currentSort !== 'newest'): ?><input type="hidden" name="sort" value="<?= e($currentSort); ?>"><?php endif; ?>

                <div class="search-input-group">
                    <input type="text" name="q" value="<?= e($query); ?>" placeholder="<?= $searchMode === 'projects' ? 'Search research projects by title, code, creator, research area, department...' : 'Search datasets by title, abstract, keywords, subject domain, creator or file format...'; ?>" autofocus>
                    <button type="submit" class="btn-search">
                        🔍 Search <?= $searchMode === 'projects' ? 'Projects' : 'Datasets'; ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="search-layout">

            <!-- Filter Sidebar -->
            <aside class="filter-sidebar">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--primary-color); margin: 0;">
                        ⚙️ Filters & Refinements
                    </h3>
                    <?php if ($hasActiveFilters): ?>
                        <a href="index.php" style="font-size: 0.75rem; color: #dc2626; text-decoration: none; font-weight: 600;">
                            Clear All
                        </a>
                    <?php endif; ?>
                </div>

                <form action="index.php" method="GET">
                    <?php if ($query !== ''): ?>
                        <input type="hidden" name="q" value="<?= e($query); ?>">
                    <?php endif; ?>

                    <!-- Subject Area -->
                    <div class="filter-group">
                        <label for="filter_subject" class="filter-label">Subject Domain</label>
                        <select id="filter_subject" name="subject" class="filter-select" onchange="this.form.submit();">
                            <option value="">All Subject Areas</option>
                            <?php foreach ($filterOptions['subjects'] as $sbj): ?>
                                <option value="<?= e($sbj); ?>" <?= ($selectedSubject === $sbj) ? 'selected' : ''; ?>>
                                    <?= e($sbj); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Data Type -->
                    <div class="filter-group">
                        <label for="filter_type" class="filter-label">Data Type</label>
                        <select id="filter_type" name="type" class="filter-select" onchange="this.form.submit();">
                            <option value="">All Data Types</option>
                            <?php foreach ($filterOptions['data_types'] as $dt): ?>
                                <option value="<?= e($dt); ?>" <?= ($selectedType === $dt) ? 'selected' : ''; ?>>
                                    <?= e($dt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- File Format -->
                    <div class="filter-group">
                        <label for="filter_format" class="filter-label">File Format</label>
                        <select id="filter_format" name="format" class="filter-select" onchange="this.form.submit();">
                            <option value="">All Formats</option>
                            <?php foreach ($filterOptions['formats'] as $fmt): ?>
                                <option value="<?= e($fmt); ?>" <?= ($selectedFormat === $fmt) ? 'selected' : ''; ?>>
                                    <?= e($fmt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Geographic Coverage -->
                    <?php if (!empty($filterOptions['geos'])): ?>
                        <div class="filter-group">
                            <label for="filter_coverage" class="filter-label">Geographic Coverage</label>
                            <select id="filter_coverage" name="coverage" class="filter-select" onchange="this.form.submit();">
                                <option value="">All Regions</option>
                                <?php foreach ($filterOptions['geos'] as $geo): ?>
                                    <option value="<?= e($geo); ?>" <?= ($selectedCoverage === $geo) ? 'selected' : ''; ?>>
                                        <?= e($geo); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Access Level Filter -->
                    <div class="filter-group">
                        <label for="filter_access" class="filter-label">Access Level</label>
                        <select id="filter_access" name="access" class="filter-select" onchange="this.form.submit();">
                            <option value="">Public & Restricted</option>
                            <option value="public" <?= ($selectedAccess === 'public') ? 'selected' : ''; ?>>Public Only</option>
                            <option value="restricted" <?= ($selectedAccess === 'restricted') ? 'selected' : ''; ?>>Restricted Only</option>
                            <option value="private" <?= ($selectedAccess === 'private') ? 'selected' : ''; ?>>My Private Datasets</option>
                        </select>
                    </div>

                    <!-- Preservation Status Filter -->
                    <div class="filter-group">
                        <label for="filter_preservation" class="filter-label">Preservation State</label>
                        <select id="filter_preservation" name="preservation" class="filter-select" onchange="this.form.submit();">
                            <option value="">All Preservation States</option>
                            <option value="preserved" <?= ($selectedPreservation === 'preserved') ? 'selected' : ''; ?>>Preserved (Locked SHA-256)</option>
                            <option value="archived" <?= ($selectedPreservation === 'archived') ? 'selected' : ''; ?>>Archived</option>
                            <option value="awaiting" <?= ($selectedPreservation === 'awaiting') ? 'selected' : ''; ?>>Awaiting Preservation</option>
                        </select>
                    </div>

                    <!-- Sorting Selection -->
                    <div class="filter-group">
                        <label for="filter_sort" class="filter-label">Sort Order</label>
                        <select id="filter_sort" name="sort" class="filter-select" onchange="this.form.submit();">
                            <?php foreach (SEARCH_SORT_WHITELIST as $sortVal => $sortLabel): ?>
                                <option value="<?= e($sortVal); ?>" <?= ($currentSort === $sortVal) ? 'selected' : ''; ?>>
                                    <?= e($sortLabel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn-card-action btn-card-primary" style="width: 100%; justify-content: center; padding: 0.6rem;">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </aside>

            <!-- Results Section -->
            <section>
                <div class="results-header-bar">
                    <div>
                        <span style="font-size: 1.05rem; font-weight: 700; color: var(--primary-color);">
                            <?php if ($total > 0): ?>
                                Showing <?= $showingStart; ?>–<?= $showingEnd; ?> of <?= $total; ?> dataset<?= $total === 1 ? '' : 's'; ?>
                            <?php else: ?>
                                0 datasets found
                            <?php endif; ?>
                        </span>
                        <?php if ($query !== ''): ?>
                            <span style="font-size: 0.85rem; color: var(--text-muted); margin-left: 0.35rem;">
                                for &ldquo;<strong><?= e($query); ?></strong>&rdquo;
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Sorted by: <strong><?= e(SEARCH_SORT_WHITELIST[$currentSort] ?? 'Newest'); ?></strong>
                    </div>
                       <!-- Empty Results State -->
                <?php if (empty($results)): ?>
                    <div class="empty-search-state">
                        <div style="font-size: 3rem; margin-bottom: 0.75rem;">🔍</div>
                        <h2 style="font-size: 1.35rem; color: var(--primary-color); font-weight: 700; margin-bottom: 0.5rem;">
                            No research <?= $searchMode === 'projects' ? 'projects' : 'datasets'; ?> found
                        </h2>
                        <p style="font-size: 0.95rem; margin-bottom: 1.5rem;">
                            No <?= $searchMode === 'projects' ? 'projects' : 'datasets'; ?> matched your search terms or active filter criteria.
                        </p>
                        <a href="index.php?mode=<?= e($searchMode); ?>" class="btn-card-action btn-card-primary" style="padding: 0.6rem 1.25rem;">
                            🔄 Reset All Filters
                        </a>
                    </div>
                <?php else: ?>
                    <!-- Results List -->
                    <?php if ($searchMode === 'projects'): ?>
                        <?php foreach ($results as $item): 
                            $pId = (int)$item['id'];
                            $st = strtolower(trim($item['status']));
                            $statusClass = ($st === 'active') ? 'background:#d1fae5; color:#065f46;' : (($st === 'completed') ? 'background:#e2e8f0; color:#334155;' : 'background:#f1f5f9; color:#475569;');
                        ?>
                            <article class="result-card">
                                <div class="result-top">
                                    <div>
                                        <span style="font-family: monospace; font-size: 0.8rem; font-weight: 700; color: var(--accent-color); background: var(--primary-light); padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); margin-right: 0.4rem;">
                                            <?= e($item['project_code']); ?>
                                        </span>
                                        <a href="../projects/view.php?id=<?= $pId; ?>" class="result-title">
                                            <?= e($item['title']); ?>
                                        </a>
                                    </div>

                                    <div>
                                        <span style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 9999px; <?= $statusClass; ?>">
                                            <?= e($item['status']); ?>
                                        </span>
                                    </div>
                                </div>

                                <p class="result-abstract">
                                    <?= e(mb_strimwidth($item['description'] ?: $item['objectives'], 0, 240, '...')); ?>
                                </p>

                                <div class="metadata-pill-row">
                                    <span class="meta-pill">👤 Lead: <?= e($item['owner_first_name'] . ' ' . $item['owner_last_name']); ?></span>
                                    <?php if (!empty($item['research_area'])): ?>
                                        <span class="meta-pill">🔬 <?= e($item['research_area']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['department'] ?: $item['institution'])): ?>
                                        <span class="meta-pill">🏛️ <?= e($item['department'] ?: $item['institution']); ?></span>
                                    <?php endif; ?>
                                    <span class="meta-pill">📊 <?= (int)$item['dataset_count']; ?> dataset(s)</span>
                                </div>

                                <div class="card-footer-actions" style="margin-top: 1rem;">
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                                        Started <?= e($item['start_date'] ?: date('M Y', strtotime($item['created_at']))); ?>
                                    </div>

                                    <div>
                                        <a href="../projects/view.php?id=<?= $pId; ?>" class="btn-card-action btn-card-primary">
                                            👁️ View Project &amp; Datasets
                                        </a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($results as $item): 
                            $dId = (int)$item['dataset_id'];
                            $pId = (int)$item['project_id'];
                            $ownerId = (int)$item['owner_id'];
                            $accessLvl = strtolower(trim($item['access_level']));
                            $projectRole = getProjectMemberRole($pdo, $pId, $userId);

                            $canDownload = canDownloadDataset($projectRole, $systemRole, $accessLvl, $ownerId, $userId, $pdo, $dId);
                            
                            // Check if user has an access request for restricted dataset
                            $userReq = null;
                            if ($accessLvl === 'restricted' && !$canDownload) {
                                $userReq = getUserLatestAccessRequest($pdo, $dId, $userId);
                            }
                        ?>
                            <article class="result-card">
                                <div class="result-top">
                                    <div>
                                        <span style="font-family: monospace; font-size: 0.8rem; font-weight: 700; color: var(--accent-color); background: var(--primary-light); padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); margin-right: 0.4rem;">
                                            <?= e($item['project_code']); ?>
                                        </span>
                                        <a href="../datasets/view.php?id=<?= $dId; ?>" class="result-title">
                                            <?= e($item['dataset_title']); ?>
                                        </a>
                                    </div>

                                    <div style="display: flex; gap: 0.35rem; align-items: center;">
                                        <span style="text-transform: uppercase; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 9999px; background: <?= $accessLvl === 'public' ? '#d1fae5; color: #065f46;' : ($accessLvl === 'restricted' ? '#fef3c7; color: #92400e;' : '#f1f5f9; color: #475569;'); ?>">
                                            <?= e($item['access_level']); ?>
                                        </span>
                                        <?= getPreservationStatusBadge($item['dataset_status']); ?>
                                    </div>
                                </div>

                                <p class="result-abstract">
                                    <?= e(mb_strimwidth($item['dataset_description'], 0, 240, '...')); ?>
                                </p>

                                <!-- Metadata attributes row -->
                                <div class="metadata-pill-row">
                                    <?php if (!empty($item['metadata_creator'])): ?>
                                        <span class="meta-pill">👤 <?= e($item['metadata_creator']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metadata_subject'])): ?>
                                        <span class="meta-pill">📚 <?= e($item['metadata_subject']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metadata_data_type'])): ?>
                                        <span class="meta-pill">📊 <?= e($item['metadata_data_type']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metadata_file_format'])): ?>
                                        <span class="meta-pill">📁 <?= e($item['metadata_file_format']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metadata_geographic'])): ?>
                                        <span class="meta-pill">🌍 <?= e($item['metadata_geographic']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['metadata_license'])): ?>
                                        <span class="meta-pill">⚖️ <?= e($item['metadata_license']); ?></span>
                                    <?php endif; ?>
                                </div>

                                <!-- Keywords tags -->
                                <?php if (!empty($item['metadata_keywords'])): ?>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.85rem;">
                                        <?php foreach (array_filter(array_map('trim', explode(',', (string)$item['metadata_keywords']))) as $kw): ?>
                                            <span class="tag-pill">#<?= e($kw); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Action Bar -->
                                <div class="card-footer-actions">
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                                        Deposited <?= date('M d, Y', strtotime($item['dataset_created_at'])); ?> by <?= e($item['owner_first_name'] . ' ' . $item['owner_last_name']); ?> &bull; 
                                        Version <?= (int)$item['current_version']; ?> &bull; <?= formatFileSize((int)$item['total_size']); ?>
                                    </div>

                                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                                        <a href="../datasets/view.php?id=<?= $dId; ?>" class="btn-card-action">
                                            👁️ View Dataset &amp; Metadata
                                        </a>

                                        <?php if ($canDownload): ?>
                                            <a href="../datasets/download.php?id=<?= $dId; ?>" class="btn-card-action btn-card-success">
                                                ⬇️ Download
                                            </a>
                                        <?php elseif ($accessLvl === 'restricted'): ?>
                                            <?php if (!$userReq): ?>
                                                <a href="../access/create.php?dataset_id=<?= $dId; ?>" class="btn-card-action btn-card-warning" title="Submit Research Access Request">
                                                    🔑 Request Access
                                                </a>
                                            <?php elseif ($userReq['status'] === 'pending'): ?>
                                                <a href="../access/view.php?id=<?= (int)$userReq['id']; ?>" class="btn-card-action" style="background: #fef3c7; color: #92400e; border-color: #fde68a;">
                                                    ⏳ Request Pending
                                                </a>
                                            <?php elseif ($userReq['status'] === 'rejected'): ?>
                                                <a href="../access/create.php?dataset_id=<?= $dId; ?>" class="btn-card-action btn-card-warning">
                                                    🔄 Re-request Access
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                    <!-- Pagination Navigation -->
                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination-container" aria-label="Repository search pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?= e(buildSearchUrl($_GET, ['page' => $page - 1])); ?>" class="page-link">&laquo; Prev</a>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2): ?>
                                    <a href="<?= e(buildSearchUrl($_GET, ['page' => $p])); ?>" class="page-link <?= ($p === $page) ? 'active' : ''; ?>">
                                        <?= $p; ?>
                                    </a>
                                <?php elseif ($p === 2 || $p === $totalPages - 1): ?>
                                    <span style="padding: 0.5rem; color: var(--text-muted);">&hellip;</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= e(buildSearchUrl($_GET, ['page' => $page + 1])); ?>" class="page-link">Next &raquo;</a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
            </section>

        </div>

    </main>

    <!-- Footer -->
    <footer class="dash-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>
