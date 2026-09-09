<?php
/**
 * Research Data Repository Search & Discovery Helpers
 * RDM Information System - Step 11
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../datasets/dataset_helpers.php';
require_once __DIR__ . '/../metadata/metadata_helpers.php';
require_once __DIR__ . '/../access/access_helpers.php';
require_once __DIR__ . '/../preservation/preservation_helpers.php';

/**
 * Whitelist of allowed sorting options
 */
define('SEARCH_SORT_WHITELIST', [
    'relevance' => 'Relevance',
    'newest'    => 'Newest Deposited',
    'oldest'    => 'Oldest Deposited',
    'title_asc' => 'Title (A – Z)',
    'title_desc'=> 'Title (Z – A)',
    'updated'   => 'Recently Modified'
]);

/**
 * Whitelist of allowed per-page limits
 */
define('SEARCH_LIMIT_WHITELIST', [20, 40, 60]);

/**
 * Build and execute search query respecting access control and dynamic filters
 */
function executeRepositorySearch(PDO $pdo, int $userId, string $userRole, array $params): array {
    $rawQuery     = trim($params['q'] ?? '');
    $subject      = trim($params['subject'] ?? '');
    $dataType     = trim($params['type'] ?? '');
    $fileFormat   = trim($params['format'] ?? '');
    $geographic   = trim($params['coverage'] ?? '');
    $accessLevel  = strtolower(trim($params['access'] ?? ''));
    $preservation = strtolower(trim($params['preservation'] ?? ''));
    $sortKey      = strtolower(trim($params['sort'] ?? 'newest'));
    $page         = max(1, (int)($params['page'] ?? 1));
    $limit        = (int)($params['limit'] ?? 20);

    if (!in_array($limit, SEARCH_LIMIT_WHITELIST, true)) {
        $limit = 20;
    }
    if (!array_key_exists($sortKey, SEARCH_SORT_WHITELIST)) {
        $sortKey = (!empty($rawQuery)) ? 'relevance' : 'newest';
    }

    $offset = ($page - 1) * $limit;
    $bindings = [];
    $whereClauses = [];

    // 1. Lifecycle Status: Exclude deleted datasets
    $whereClauses[] = "d.status != 'deleted'";

    // 2. Access Control & Visibility Protection (CRITICAL)
    $isAdmin = (strtolower($userRole) === 'admin');
    if (!$isAdmin) {
        $whereClauses[] = "(
            d.access_level IN ('public', 'restricted')
            OR (
                d.access_level = 'private' 
                AND (
                    d.owner_id = :auth_user_id1 
                    OR d.project_id IN (
                        SELECT project_id FROM project_members WHERE user_id = :auth_user_id2
                    )
                )
            )
        )";
        $bindings[':auth_user_id1'] = $userId;
        $bindings[':auth_user_id2'] = $userId;
    }

    // 3. Text Search Query across multi-field metadata
    if ($rawQuery !== '') {
        $whereClauses[] = "(
            d.title LIKE :q_like1
            OR d.description LIKE :q_like2
            OR dm.keywords LIKE :q_like3
            OR dm.subject_area LIKE :q_like4
            OR dm.creator LIKE :q_like5
            OR dm.geographic_coverage LIKE :q_like6
            OR dm.data_type LIKE :q_like7
            OR dm.file_format LIKE :q_like8
            OR p.title LIKE :q_like9
            OR p.project_code LIKE :q_like10
        )";
        $likeVal = '%' . $rawQuery . '%';
        for ($i = 1; $i <= 10; $i++) {
            $bindings[":q_like{$i}"] = $likeVal;
        }
    }

    // 4. Dynamic Filters
    if ($subject !== '') {
        $whereClauses[] = "dm.subject_area = :filter_subject";
        $bindings[':filter_subject'] = $subject;
    }
    if ($dataType !== '') {
        $whereClauses[] = "dm.data_type = :filter_type";
        $bindings[':filter_type'] = $dataType;
    }
    if ($fileFormat !== '') {
        $whereClauses[] = "dm.file_format = :filter_format";
        $bindings[':filter_format'] = $fileFormat;
    }
    if ($geographic !== '') {
        $whereClauses[] = "dm.geographic_coverage = :filter_geo";
        $bindings[':filter_geo'] = $geographic;
    }
    if ($accessLevel !== '' && in_array($accessLevel, ['public', 'restricted', 'private'], true)) {
        $whereClauses[] = "d.access_level = :filter_access";
        $bindings[':filter_access'] = $accessLevel;
    }
    if ($preservation !== '') {
        if ($preservation === 'preserved') {
            $whereClauses[] = "d.status = 'preserved'";
        } elseif ($preservation === 'archived') {
            $whereClauses[] = "d.status = 'archived'";
        } elseif ($preservation === 'awaiting') {
            $whereClauses[] = "d.status NOT IN ('preserved', 'archived', 'deleted')";
        }
    }

    $whereSql = implode(' AND ', $whereClauses);

    // 5. Total Count Query (for safe pagination calculation)
    $countSql = "
        SELECT COUNT(DISTINCT d.id) 
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
        WHERE {$whereSql}
    ";

    $totalResults = 0;
    try {
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bindings);
        $totalResults = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Search Count Query Error: " . $e->getMessage());
        return [
            'results'      => [],
            'total'        => 0,
            'page'         => 1,
            'limit'        => $limit,
            'total_pages'  => 0,
            'query'        => $rawQuery,
            'sort'         => $sortKey
        ];
    }

    $totalPages = (int)ceil($totalResults / $limit);
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    // 6. Sorting Clause Construction
    $relevanceSelect = "0 AS relevance_score";
    if ($rawQuery !== '') {
        $relevanceSelect = "(
            CASE 
                WHEN d.title = :q_exact THEN 100
                WHEN d.title LIKE :q_like_rel THEN 60
                WHEN dm.keywords LIKE :q_like_rel THEN 40
                WHEN dm.subject_area LIKE :q_like_rel THEN 30
                WHEN dm.creator LIKE :q_like_rel THEN 20
                WHEN d.description LIKE :q_like_rel THEN 15
                ELSE 5
            END
        ) AS relevance_score";
        $bindings[':q_exact']    = $rawQuery;
        $bindings[':q_like_rel'] = '%' . $rawQuery . '%';
    }

    switch ($sortKey) {
        case 'relevance':
            $orderSql = ($rawQuery !== '') ? "ORDER BY relevance_score DESC, d.created_at DESC" : "ORDER BY d.created_at DESC";
            break;
        case 'oldest':
            $orderSql = "ORDER BY d.created_at ASC";
            break;
        case 'title_asc':
            $orderSql = "ORDER BY d.title ASC";
            break;
        case 'title_desc':
            $orderSql = "ORDER BY d.title DESC";
            break;
        case 'updated':
            $orderSql = "ORDER BY d.updated_at DESC";
            break;
        case 'newest':
        default:
            $orderSql = "ORDER BY d.created_at DESC";
            break;
    }

    // 7. Results Retrieval Query
    $dataSql = "
        SELECT 
            d.id AS dataset_id,
            d.title AS dataset_title,
            d.description AS dataset_description,
            d.dataset_type,
            d.access_level,
            d.status AS dataset_status,
            d.current_version,
            d.total_size,
            d.download_count,
            d.view_count,
            d.created_at AS dataset_created_at,
            d.updated_at AS dataset_updated_at,
            p.id AS project_id,
            p.project_code,
            p.title AS project_title,
            u.id AS owner_id,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            dm.id AS metadata_id,
            dm.creator AS metadata_creator,
            dm.subject_area AS metadata_subject,
            dm.keywords AS metadata_keywords,
            dm.data_type AS metadata_data_type,
            dm.file_format AS metadata_file_format,
            dm.geographic_coverage AS metadata_geographic,
            dm.license AS metadata_license,
            {$relevanceSelect}
        FROM datasets d
        INNER JOIN research_projects p ON d.project_id = p.id
        INNER JOIN users u ON d.owner_id = u.id
        LEFT JOIN dataset_metadata dm ON d.id = dm.dataset_id
        WHERE {$whereSql}
        GROUP BY d.id
        {$orderSql}
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ";

    $results = [];
    try {
        $stmt = $pdo->prepare($dataSql);
        $stmt->execute($bindings);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Search Execution Query Error: " . $e->getMessage());
    }

    return [
        'results'     => $results,
        'total'       => $totalResults,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => $totalPages,
        'query'       => $rawQuery,
        'sort'        => $sortKey
    ];
}

/**
 * Query available filter options populated dynamically from discoverable metadata
 */
function getAvailableFilterOptions(PDO $pdo, int $userId, string $userRole): array {
    $isAdmin = (strtolower($userRole) === 'admin');
    $authWhere = "";
    $params = [];

    if (!$isAdmin) {
        $authWhere = "AND (
            d.access_level IN ('public', 'restricted')
            OR (
                d.access_level = 'private' 
                AND (
                    d.owner_id = :u1 
                    OR d.project_id IN (SELECT project_id FROM project_members WHERE user_id = :u2)
                )
            )
        )";
        $params[':u1'] = $userId;
        $params[':u2'] = $userId;
    }

    $options = [
        'subjects'   => [],
        'data_types' => [],
        'formats'    => [],
        'geos'       => []
    ];

    try {
        // Subjects
        $stmt = $pdo->prepare("
            SELECT DISTINCT dm.subject_area 
            FROM dataset_metadata dm
            INNER JOIN datasets d ON dm.dataset_id = d.id
            WHERE d.status != 'deleted' AND dm.subject_area IS NOT NULL AND dm.subject_area != '' {$authWhere}
            ORDER BY dm.subject_area ASC
        ");
        $stmt->execute($params);
        $options['subjects'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Data Types
        $stmt = $pdo->prepare("
            SELECT DISTINCT dm.data_type 
            FROM dataset_metadata dm
            INNER JOIN datasets d ON dm.dataset_id = d.id
            WHERE d.status != 'deleted' AND dm.data_type IS NOT NULL AND dm.data_type != '' {$authWhere}
            ORDER BY dm.data_type ASC
        ");
        $stmt->execute($params);
        $options['data_types'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Formats
        $stmt = $pdo->prepare("
            SELECT DISTINCT dm.file_format 
            FROM dataset_metadata dm
            INNER JOIN datasets d ON dm.dataset_id = d.id
            WHERE d.status != 'deleted' AND dm.file_format IS NOT NULL AND dm.file_format != '' {$authWhere}
            ORDER BY dm.file_format ASC
        ");
        $stmt->execute($params);
        $options['formats'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Geographic Coverage
        $stmt = $pdo->prepare("
            SELECT DISTINCT dm.geographic_coverage 
            FROM dataset_metadata dm
            INNER JOIN datasets d ON dm.dataset_id = d.id
            WHERE d.status != 'deleted' AND dm.geographic_coverage IS NOT NULL AND dm.geographic_coverage != '' {$authWhere}
            ORDER BY dm.geographic_coverage ASC
        ");
        $stmt->execute($params);
        $options['geos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        error_log("Filter Options Query Error: " . $e->getMessage());
    }

    return $options;
}

/**
 * Generate sanitized URL string for search pagination and sort links
 */
function buildSearchUrl(array $params, array $override = []): string {
    $merged = array_merge($params, $override);
    $clean = [];
    foreach ($merged as $k => $v) {
        if ($v !== null && $v !== '') {
            $clean[$k] = $v;
        }
    }
    return 'index.php?' . http_build_query($clean);
}

/**
 * Build and execute project search query respecting status and access rules
 */
function executeProjectSearch(PDO $pdo, int $userId, string $userRole, array $params): array {
    $rawQuery     = trim($params['q'] ?? '');
    $statusFilter = strtolower(trim($params['status'] ?? ''));
    $page         = max(1, (int)($params['page'] ?? 1));
    $limit        = 15;
    $offset       = ($page - 1) * $limit;

    $bindings = [':current_user_id' => $userId];
    $where = [];

    // All institutional research projects discoverable in search portal
    $where[] = "1=1";

    if ($rawQuery !== '') {
        $where[] = "(
            p.title LIKE :q1
            OR p.project_code LIKE :q2
            OR p.description LIKE :q3
            OR p.objectives LIKE :q4
            OR p.research_area LIKE :q5
            OR p.department LIKE :q6
            OR p.faculty LIKE :q7
            OR u.first_name LIKE :q8
            OR u.last_name LIKE :q9
            OR CONCAT(u.first_name, ' ', u.last_name) LIKE :q10
        )";
        $likeVal = '%' . $rawQuery . '%';
        for ($i = 1; $i <= 10; $i++) {
            $bindings[":q{$i}"] = $likeVal;
        }
    }

    if ($statusFilter !== '' && in_array($statusFilter, ['active', 'completed', 'planning', 'archived', 'suspended', 'draft'], true)) {
        $where[] = "p.status = :status_filter";
        $bindings[':status_filter'] = $statusFilter;
    }

    $whereSql = implode(' AND ', $where);

    // Count total
    $countSql = "
        SELECT COUNT(DISTINCT p.id)
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :current_user_id
        WHERE {$whereSql}
    ";
    $cStmt = $pdo->prepare($countSql);
    $cStmt->execute($bindings);
    $total = (int)$cStmt->fetchColumn();

    // Fetch page items
    $dataSql = "
        SELECT 
            p.*,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.email AS owner_email,
            u.department AS owner_department,
            u.institution AS owner_institution,
            pm.role AS user_member_role,
            COUNT(d.id) AS dataset_count
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :current_user_id
        LEFT JOIN datasets d ON p.id = d.project_id AND d.status != 'deleted' AND d.access_level IN ('public', 'restricted')
        WHERE {$whereSql}
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $dStmt = $pdo->prepare($dataSql);
    $dStmt->execute($bindings);
    $results = $dStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'results'     => $results,
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => max(1, (int)ceil($total / $limit)),
        'query'       => $rawQuery
    ];
}
