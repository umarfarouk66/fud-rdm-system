<?php

$host = "localhost";
$dbname = "rdm_system";
$username = "root";
$password = "";

// Optional local database override (git-ignored)
if (file_exists(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}