<?php
/**
 * Search Results Endpoint
 * RDM Information System - Step 11
 */

$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: index.php{$queryString}");
exit;
