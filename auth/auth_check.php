<?php
/**
 * Authentication and Role-Based Access Control Middleware
 * RDM Information System
 */

require_once __DIR__ . '/../config/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if a user is currently authenticated
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/**
 * Get current authenticated user details
 */
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'          => (int)$_SESSION['user_id'],
        'name'        => $_SESSION['user_name'] ?? '',
        'first_name'  => $_SESSION['first_name'] ?? '',
        'last_name'   => $_SESSION['last_name'] ?? '',
        'email'       => $_SESSION['user_email'] ?? '',
        'role_id'     => (int)($_SESSION['role_id'] ?? 0),
        'role'        => $_SESSION['role'] ?? '',
        'institution' => $_SESSION['institution'] ?? '',
        'faculty'     => $_SESSION['faculty'] ?? '',
        'department'  => $_SESSION['department'] ?? ''
    ];
}

/**
 * Get dashboard URL for a given role
 */
function getDashboardUrl(?string $role = null, string $basePrefix = '../'): string {
    $role = $role ?? ($_SESSION['role'] ?? 'researcher');
    switch (strtolower(trim($role))) {
        case 'super_admin':
            return $basePrefix . 'admin/super_admin_governance.php';
        case 'admin':
            return $basePrefix . 'admin/dashboard.php';
        case 'supervisor':
            return $basePrefix . 'supervisor/dashboard.php';
        case 'librarian':
            return $basePrefix . 'librarian/dashboard.php';
        case 'guest':
            return $basePrefix . 'public/index.php';
        case 'researcher':
        default:
            return $basePrefix . 'researcher/dashboard.php';
    }
}

/**
 * Enforce authentication on protected pages
 */
function requireAuth(string $loginUrl = '../public/login.php'): void {
    if (!isLoggedIn()) {
        $_SESSION['auth_error'] = 'Please log in to access this page.';
        header("Location: $loginUrl");
        exit;
    }

    // Auto-enforce read-only guards for Super Admin on non-GET / mutation requests
    if (isSuperAdmin()) {
        enforceWritePermission();
    }
}

/**
 * Enforce role-based authorization on protected pages
 * 
 * @param string|array $allowedRoles Single role name or array of allowed roles
 * @param string $loginUrl URL to redirect unauthenticated users
 */
function requireRole($allowedRoles, string $loginUrl = '../public/login.php'): void {
    requireAuth($loginUrl);

    $allowedRoles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    $userRole = strtolower(trim($_SESSION['role'] ?? ''));

    $allowedLower = array_map('strtolower', array_map('trim', $allowedRoles));

    // Super Admin has read-only monitoring access across system endpoints
    if ($userRole === 'super_admin') {
        enforceWritePermission();
        return;
    }

    if (!in_array($userRole, $allowedLower, true)) {
        // Access denied: redirect user to their own role's dashboard
        $_SESSION['access_error'] = 'Access denied: You do not have permission to view that page.';
        $userDashboard = getDashboardUrl($userRole, '../');
        header("Location: $userDashboard");
        exit;
    }
}
