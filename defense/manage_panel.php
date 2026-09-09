<?php
/**
 * Defense Panel & Examiners Management Controller
 * FUD RDM System - Phase 8: Final Project Approval + Defense/Viva Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../notifications/notification_helper.php';
require_once __DIR__ . '/defense_helpers.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../admin/defense_management.php');
    exit;
}

// Super Admin is strictly READ-ONLY
if (isSuperAdmin()) {
    http_response_code(403);
    die('Access Denied: Super Admin account is strictly read-only.');
}

requireRole('admin');
enforceWritePermission();
verifyCSRFToken();

$user   = currentUser();
$userId = (int)$user['id'];

$defenseId = (int)($_POST['defense_id'] ?? 0);
$action    = trim($_POST['action'] ?? ''); // 'add' or 'remove'

if ($defenseId <= 0 || !in_array($action, ['add', 'remove'], true)) {
    $_SESSION['flash_error'] = 'Invalid defense panel action inputs.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
    exit;
}

// Check defense exists
$dStmt = $pdo->prepare("SELECT d.*, rp.title AS project_title, rp.owner_id AS student_id FROM project_defenses d INNER JOIN research_projects rp ON d.project_id = rp.id WHERE d.id = :id LIMIT 1");
$dStmt->execute([':id' => $defenseId]);
$defense = $dStmt->fetch(PDO::FETCH_ASSOC);

if (!$defense) {
    $_SESSION['flash_error'] = 'Defense record not found.';
    header('Location: ../admin/defense_management.php');
    exit;
}

if ($action === 'add') {
    $memberUserId   = (int)($_POST['user_id'] ?? 0);
    $extName        = trim($_POST['external_name'] ?? '');
    $extEmail       = trim($_POST['external_email'] ?? '');
    $extInstitution = trim($_POST['external_institution'] ?? '');
    $panelRole      = trim($_POST['panel_role'] ?? 'internal_examiner');

    $validRoles = ['chair', 'internal_examiner', 'external_examiner', 'supervisor_member', 'observer'];
    if (!in_array($panelRole, $validRoles, true)) {
        $panelRole = 'internal_examiner';
    }

    if ($memberUserId <= 0 && empty($extName)) {
        $_SESSION['flash_error'] = 'Please select a system user or enter external examiner details.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
        exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO defense_panel_members (
            defense_id, user_id, external_examiner_name, external_examiner_email, 
            external_examiner_institution, panel_role, created_at
        ) VALUES (
            :did, :uid, :ename, :eemail, :einst, :prole, NOW()
        )
    ");
    $ins->execute([
        ':did'    => $defenseId,
        ':uid'    => $memberUserId > 0 ? $memberUserId : null,
        ':ename'  => !empty($extName) ? $extName : null,
        ':eemail' => !empty($extEmail) ? $extEmail : null,
        ':einst'  => !empty($extInstitution) ? $extInstitution : null,
        ':prole'  => $panelRole
    ]);

    // If internal system user added, notify them
    if ($memberUserId > 0) {
        $roleLabel = str_replace('_', ' ', ucfirst($panelRole));
        createNotification(
            $pdo, 
            $memberUserId, 
            'panel_appointment', 
            '🎓 Appointed to Defense Examination Panel', 
            "You have been appointed as {$roleLabel} for the oral defense of '{$defense['project_title']}' on " . date('M d, Y', strtotime($defense['defense_date'])) . ".", 
            "../admin/defense_management.php?inspect_id={$defenseId}"
        );
    }

    // Audit log
    log_audit($pdo, 'defense_panel_member_added', 'defense_panel_member', $defenseId, "Added panel member (Role: {$panelRole}) to defense #{$defenseId}.");

    $_SESSION['flash_success'] = 'Defense panel member added successfully.';
} elseif ($action === 'remove') {
    $panelMemberId = (int)($_POST['panel_member_id'] ?? 0);
    if ($panelMemberId <= 0) {
        $_SESSION['flash_error'] = 'Invalid panel member identifier.';
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
        exit;
    }

    $del = $pdo->prepare("DELETE FROM defense_panel_members WHERE id = :id AND defense_id = :did");
    $del->execute([':id' => $panelMemberId, ':did' => $defenseId]);

    // Audit log
    log_audit($pdo, 'defense_panel_member_removed', 'defense_panel_member', $defenseId, "Removed panel member #{$panelMemberId} from defense #{$defenseId}.");

    $_SESSION['flash_success'] = 'Panel member removed.';
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../admin/defense_management.php'));
exit;
