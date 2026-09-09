<?php
/**
 * Admin Reports Router
 * RDM Information System - Step 12
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../auth/auth_check.php';

requireRole('admin');
header('Location: ../reports/index.php');
exit;
