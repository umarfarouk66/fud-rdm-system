<?php
/**
 * Security & Helper Utilities
 * RDM Information System
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Escape HTML output to prevent XSS
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize text input string
 */
function sanitize_input(?string $data): string {
    if ($data === null) {
        return '';
    }
    return trim(strip_tags($data));
}

/**
 * Generate or get current CSRF token
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate CSRF hidden input field for forms
 */
function csrf_field(): string {
    $token = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Validate CSRF token from request
 */
function validate_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Standard CSRF aliases
 */
function generateCsrfToken(): string {
    return csrf_token();
}

function verifyCsrfToken(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? null;
    }
    return validate_csrf_token($token);
}

if (!function_exists('csrfField')) {
    function csrfField(): string {
        return csrf_field();
    }
}

/**
 * Safe redirect helper
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Record an entry in audit_logs
 * 
 * @param PDO $pdo
 * @param string $action Action name (e.g. 'project_created')
 * @param string|null $entityType Entity table/name (e.g. 'research_project')
 * @param int|null $entityId Entity primary ID
 * @param string|null $description Narrative of the action
 * @param int|null $userId Optional user ID (defaults to current session user_id)
 * @return bool
 */
function log_audit(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    ?int $userId = null
): bool {
    try {
        $actualUserId = $userId ?? ($_SESSION['user_id'] ?? null);
        $ipAddress    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System';

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                description,
                ip_address,
                user_agent
            ) VALUES (
                :user_id,
                :action,
                :entity_type,
                :entity_id,
                :description,
                :ip_address,
                :user_agent
            )
        ");

        return $stmt->execute([
            ':user_id'     => $actualUserId ? (int)$actualUserId : null,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId ? (int)$entityId : null,
            ':description' => $description,
            ':ip_address'  => substr($ipAddress, 0, 45),
            ':user_agent'  => $userAgent
        ]);
    } catch (PDOException $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if the current logged-in user is a Super Admin
 */
function isSuperAdmin(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return strtolower(trim($_SESSION['role'] ?? '')) === 'super_admin';
}

/**
 * Enforce server-side write permission guards for Super Admin.
 * Rejects POST, PUT, DELETE, PATCH requests and mutation actions from super_admin with HTTP 403 Forbidden.
 */
function enforceWritePermission(string $redirectUrl = '../admin/dashboard.php'): void {
    if (isSuperAdmin()) {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $action = strtolower(trim($_REQUEST['action'] ?? ''));

        // Controlled Exception: Super Admin is permitted to manage Admin designations exclusively
        if (($action === 'designate_admin' || $action === 'revoke_admin') && $method === 'POST') {
            return;
        }

        $mutationActions = [
            'delete', 'approve', 'reject', 'update', 'store', 'create', 'assign', 'unassign',
            'toggle', 'cancel', 'archive', 'publish', 'sync', 'mark_read', 'schedule', 'record',
            'signoff', 'restore', 'save', 'send', 'upload', 'edit', 'modify', 'submit'
        ];
        
        $isMutationMethod = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);
        $isMutationAction = in_array($action, $mutationActions, true);

        if ($isMutationMethod || $isMutationAction) {
            http_response_code(403);
            $msg = 'Access Denied: Super Admin is a read-only monitoring role and cannot execute modification operations.';
            
            // Handle JSON / AJAX requests safely
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                   || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $msg, 'message' => $msg]);
                exit;
            }

            $_SESSION['admin_error']  = $msg;
            $_SESSION['access_error'] = $msg;
            
            if (!empty($_SERVER['HTTP_REFERER'])) {
                header('Location: ' . $_SERVER['HTTP_REFERER']);
            } else {
                header('Location: ' . $redirectUrl);
            }
            exit;
        }
    }
}

