<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'scan_sml_db';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    // Users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(255) NOT NULL,
            `department` VARCHAR(255) NOT NULL DEFAULT '',
            `role` ENUM('admin','warehouse','sale_admin') NOT NULL DEFAULT 'warehouse',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Ensure 'sale_admin' exists in ENUM if table was already created
    try {
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','warehouse','sale_admin') NOT NULL DEFAULT 'warehouse'");
    } catch (PDOException $e) {
        // Ignore if error (e.g. column already matches)
    }

    // Receive table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `receive` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `po` VARCHAR(100) NOT NULL DEFAULT '',
            `model` VARCHAR(255) NOT NULL DEFAULT '',
            `part_no` VARCHAR(255) NOT NULL DEFAULT '',
            `serial` VARCHAR(255) NOT NULL,
            `warranty` VARCHAR(255) NOT NULL DEFAULT '',
            `status` VARCHAR(50) NOT NULL DEFAULT 'Open',
            `time_scan_in` DATETIME DEFAULT NULL,
            `name_scan_in` VARCHAR(255) DEFAULT NULL,
            `time_scan_out` DATETIME DEFAULT NULL,
            `name_scan_out` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_serial` (`serial`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Pick table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pick` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `so` VARCHAR(100) NOT NULL DEFAULT '',
            `serial` VARCHAR(255) NOT NULL DEFAULT '',
            `model` VARCHAR(255) NOT NULL DEFAULT '',
            `part_no` VARCHAR(255) NOT NULL DEFAULT '',
            `date_time` DATETIME NOT NULL,
            `status` ENUM('Pass','Fail') NOT NULL DEFAULT 'Pass',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Seed default admin user if empty
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, full_name, department, role) VALUES (?, ?, ?, ?, ?)")
            ->execute(['admin', $hash, 'Administrator', 'IT', 'admin']);

        $hash2 = password_hash('wh123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, full_name, department, role) VALUES (?, ?, ?, ?, ?)")
            ->execute(['warehouse', $hash2, 'Warehouse Staff', 'Warehouse', 'warehouse']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper functions
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// Session management
session_start();
