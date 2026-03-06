<?php
require_once __DIR__ . '/config.php';

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// Today's requisitions count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE DATE(created_at) = ?");
$stmt->execute([$today]);
$todayCount = (int) $stmt->fetchColumn();

// Today's total items
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_items), 0) FROM requisitions WHERE DATE(created_at) = ?");
$stmt->execute([$today]);
$todayItems = (int) $stmt->fetchColumn();

// This month's requisitions count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM requisitions WHERE DATE(created_at) >= ?");
$stmt->execute([$monthStart]);
$monthCount = (int) $stmt->fetchColumn();

// Total products
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Low stock products
$lowStock = $pdo->query("SELECT id, name, sku, stock, unit, min_stock FROM products WHERE stock <= min_stock ORDER BY stock ASC LIMIT 10")->fetchAll();

// Recent requisitions
$recent = $pdo->query("SELECT * FROM requisitions ORDER BY created_at DESC LIMIT 8")->fetchAll();

jsonResponse([
    'today_count' => $todayCount,
    'today_items' => $todayItems,
    'month_count' => $monthCount,
    'total_products' => $totalProducts,
    'low_stock' => $lowStock,
    'recent' => $recent,
]);
