<?php
/**
 * Public Landing Page & Research Directory
 * FUD RDM System - Federal University Dutse
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

$isUserLoggedIn = isLoggedIn();
$currentUser    = $isUserLoggedIn ? currentUser() : null;
$userRole       = $currentUser ? $currentUser['role'] : 'guest';

// Fetch all institutional research projects with lead owner and dataset count
$projects = [];
try {
    $stmt = $pdo->query("
        SELECT 
            p.*,
            u.first_name AS owner_first_name,
            u.last_name AS owner_last_name,
            u.department AS owner_department,
            u.institution AS owner_institution,
            COUNT(d.id) AS dataset_count
        FROM research_projects p
        INNER JOIN users u ON p.owner_id = u.id
        LEFT JOIN datasets d ON p.id = d.project_id AND d.status != 'deleted' AND d.access_level IN ('public', 'restricted')
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Public Projects Query Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Research Projects & Repository — FUD RDM System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=2.1">
    <style>
        .public-projects-section {
            max-width: 1200px;
            margin: 3.5rem auto 4rem auto;
            padding: 0 1.5rem;
        }
        .section-header-box {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        .section-tag {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--accent-color, #2563eb);
            background: rgba(37, 99, 235, 0.1);
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            display: inline-block;
            margin-bottom: 0.5rem;
        }
        .section-main-title {
            font-size: 2.1rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        .section-sub-title {
            color: #64748b;
            font-size: 1rem;
            max-width: 680px;
            margin: 0 auto;
            line-height: 1.5;
        }
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }
        .public-project-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .public-project-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }
        .project-meta-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
            gap: 0.5rem;
        }
        .project-code-badge {
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: 700;
            color: #1d4ed8;
            background: #eff6ff;
            padding: 0.25rem 0.6rem;
            border-radius: 4px;
            border: 1px solid #dbeafe;
        }
        .status-pill {
            text-transform: uppercase;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            letter-spacing: 0.04em;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-completed { background: #e2e8f0; color: #334155; }
        .status-planning { background: #e0e7ff; color: #3730a3; }
        .status-other { background: #f1f5f9; color: #475569; }

        .project-card-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5rem;
            line-height: 1.35;
        }
        .project-card-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .project-card-title a:hover {
            color: #2563eb;
        }
        .project-card-desc {
            font-size: 0.875rem;
            color: #475569;
            line-height: 1.55;
            margin-bottom: 1.25rem;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .project-card-footer {
            padding-top: 0.85rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.825rem;
            color: #64748b;
        }
        .project-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.85rem;
            flex-wrap: wrap;
            gap: 0.35rem;
        }
        .btn-view-project {
            display: block;
            width: 100%;
            text-align: center;
            background: #1e3a8a;
            color: #ffffff;
            text-decoration: none;
            padding: 0.6rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 6px;
            transition: background 0.15s ease;
        }
        .btn-view-project:hover {
            background: #2563eb;
        }
        .empty-projects-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 3rem 1.5rem;
            text-align: center;
            color: #64748b;
        }
    </style>
</head>
<body>

    <!-- Site Header -->
    <header class="site-header">
        <a href="index.php" class="brand-container">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" class="brand-logo" style="height: 46px; max-height: 46px; width: auto; object-fit: contain; display: block;">
            <div>
                <div class="brand-title">FUD RDM System</div>
                <div class="brand-subtitle">Federal University Dutse</div>
            </div>
        </a>
        <nav class="nav-links">
            <?php if ($isUserLoggedIn && $currentUser): ?>
                <a href="<?= e(getDashboardUrl($userRole, '../')); ?>" class="btn btn-outline">Dashboard (<?= e($userRole); ?>)</a>
                <a href="../auth/logout.php" class="btn btn-primary" style="background: #dc2626; border-color: #dc2626;">Sign Out</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-outline">Sign In</a>
                <a href="register.php" class="btn btn-primary">Create Account</a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- Hero Section -->
    <main class="hero-section">
        <div class="hero-content">
            <span class="hero-badge">Federal University Dutse &bull; Research Data Management</span>
            <h1 class="hero-title">Manage, Preserve and Share Institutional Research Data</h1>
            <p class="hero-subtitle">
                A secure, unified platform designed for researchers, supervisors, data stewards and administrators to govern data management plans, research projects and Findable, Accessible, Interoperable and Reusable (FAIR) data repositories.
            </p>

            <!-- Public Repository Discovery Search -->
            <div style="max-width: 680px; margin: 1.5rem auto 2rem auto;">
                <form action="../search/index.php" method="GET" style="display: flex; gap: 0.5rem; background: #ffffff; padding: 0.4rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <input type="text" name="q" placeholder="Search research datasets, projects, creators or subject areas..." style="flex: 1; border: none; outline: none; padding: 0.75rem 1rem; font-size: 1rem; color: #1e293b; border-radius: 6px;" required>
                    <button type="submit" style="background: var(--accent-color, #2563eb); color: #ffffff; border: none; padding: 0.75rem 1.5rem; font-weight: 700; font-size: 0.95rem; border-radius: 6px; cursor: pointer; white-space: nowrap;">
                        🔍 Search Repository
                    </button>
                </form>
                <div style="font-size: 0.85rem; color: rgba(255,255,255,0.85); text-align: center; margin-top: 0.5rem;">
                    Publicly discover research datasets, metadata and institutional outputs without logging in.
                </div>
            </div>

            <div class="hero-actions">
                <?php if ($isUserLoggedIn): ?>
                    <a href="<?= e(getDashboardUrl($userRole, '../')); ?>" class="btn btn-primary" style="padding: 0.85rem 1.75rem; font-size: 1.05rem;">
                        Go to My Dashboard
                    </a>
                    <a href="../search/index.php" class="btn btn-outline" style="padding: 0.85rem 1.75rem; font-size: 1.05rem;">
                        Explore Repository Search
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary" style="padding: 0.85rem 1.75rem; font-size: 1.05rem;">
                        Sign In to Portal
                    </a>
                    <a href="register.php" class="btn btn-outline" style="padding: 0.85rem 1.75rem; font-size: 1.05rem;">
                        Register as Researcher
                    </a>
                <?php endif; ?>
            </div>

            <!-- Highlights Grid -->
            <div class="features-grid">
                <div class="feature-card">
                    <span class="feature-icon">📁</span>
                    <h3>Project Governance</h3>
                    <p>Organize research outputs, ethical documentation and funding milestones.</p>
                </div>
                <div class="feature-card">
                    <span class="feature-icon">📊</span>
                    <h3>Dataset Stewardship</h3>
                    <p>Deposit, version and curate datasets adhering to Findable, Accessible, Interoperable and Reusable (FAIR) data principles and institutional standards.</p>
                </div>
                <div class="feature-card">
                    <span class="feature-icon">📝</span>
                    <h3>Data Management Plans</h3>
                    <p>Author and review compliant DMPs with automated supervisor workflows.</p>
                </div>
                <div class="feature-card">
                    <span class="feature-icon">🔒</span>
                    <h3>Role-Based Access</h3>
                    <p>Granular permissions safeguarding sensitive academic data and research assets.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Institutional Research Projects Directory Showcase -->
    <section class="public-projects-section">
        <div class="section-header-box">
            <span class="section-tag">Institutional Directory</span>
            <h2 class="section-main-title">Explore Institutional Research Projects</h2>
            <p class="section-sub-title">
                Discover active research initiatives, institutional project codes, lead investigators, and associated data repositories at Federal University Dutse.
            </p>
        </div>

        <?php if (empty($projects)): ?>
            <div class="empty-projects-box">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📁</div>
                <h3 style="font-size: 1.2rem; color: #1e293b; margin-bottom: 0.25rem;">No Research Projects Registered</h3>
                <p style="font-size: 0.9rem;">Institutional research projects will be listed here as researchers create and publish project records.</p>
            </div>
        <?php else: ?>
            <div class="projects-grid">
                <?php foreach ($projects as $proj): 
                    $st = strtolower(trim($proj['status']));
                    $statusClass = ($st === 'active') ? 'status-active' : (($st === 'completed') ? 'status-completed' : (($st === 'planning') ? 'status-planning' : 'status-other'));
                    $descText = $proj['description'] ?: ($proj['objectives'] ?: 'Institutional research project conducted at Federal University Dutse.');
                ?>
                    <article class="public-project-card">
                        <div>
                            <div class="project-meta-top">
                                <span class="project-code-badge"><?= e($proj['project_code']); ?></span>
                                <span class="status-pill <?= $statusClass; ?>"><?= e($proj['status']); ?></span>
                            </div>

                            <h3 class="project-card-title">
                                <a href="../projects/view.php?id=<?= (int)$proj['id']; ?>">
                                    <?= e($proj['title']); ?>
                                </a>
                            </h3>

                            <p class="project-card-desc">
                                <?= e(mb_strimwidth(strip_tags($descText), 0, 170, '...')); ?>
                            </p>
                        </div>

                        <div class="project-card-footer">
                            <div class="project-info-row">
                                <div>👤 Lead: <strong><?= e($proj['owner_first_name'] . ' ' . $proj['owner_last_name']); ?></strong></div>
                                <div>📊 <strong><?= (int)$proj['dataset_count']; ?> dataset(s)</strong></div>
                            </div>
                            <div style="font-size: 0.775rem; color: #94a3b8; margin-bottom: 0.85rem;">
                                🏛️ <?= e($proj['department'] ?: ($proj['research_area'] ?: $proj['institution'])); ?>
                            </div>

                            <a href="../projects/view.php?id=<?= (int)$proj['id']; ?>" class="btn-view-project">
                                👁️ View Project &amp; Datasets
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Site Footer -->
    <footer class="site-footer">
        <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
            <img src="../assets/images/fud_logo.png" alt="FUD Logo" style="height: 18px; max-height: 18px; width: auto; vertical-align: middle; object-fit: contain;">
            <span>&copy; <?= date('Y'); ?> FUD RDM System &bull; Federal University Dutse</span>
        </div>
    </footer>

</body>
</html>