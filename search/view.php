<?php
/**
 * Search Dataset View Router
 * RDM Information System - Step 11
 */

$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
    header("Location: ../datasets/view.php?id={$id}");
    exit;
}

header('Location: index.php');
exit;
