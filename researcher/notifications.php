<?php
/**
 * Notifications Router
 * RDM Information System - Step 13
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

requireAuth();
header('Location: ../notifications/index.php');
exit;
