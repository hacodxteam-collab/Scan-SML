<?php
require_once __DIR__ . '/config.php';

// Dashboard / Status stats
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// Total items received
$totalReceive = (int) $pdo->query("SELECT COUNT(*) FROM receive")->fetchColumn();

// Open items
$openItems = (int) $pdo->query("SELECT COUNT(*) FROM receive WHERE status = 'Open'")->fetchColumn();

// Picked items (Confirm Picking)
$pickedItems = (int) $pdo->query("SELECT COUNT(*) FROM receive WHERE status = 'Confirm Picking'")->fetchColumn();

// Today's picks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pick WHERE DATE(date_time) = ?");
$stmt->execute([$today]);
$todayPicks = (int) $stmt->fetchColumn();

// This month's picks
$stmt = $pdo->prepare("SELECT COUNT(*) FROM pick WHERE DATE(date_time) >= ?");
$stmt->execute([$monthStart]);
$monthPicks = (int) $stmt->fetchColumn();

// Pass/Fail counts
$passCount = (int) $pdo->query("SELECT COUNT(*) FROM pick WHERE status = 'Pass'")->fetchColumn();
$failCount = (int) $pdo->query("SELECT COUNT(*) FROM pick WHERE status = 'Fail'")->fetchColumn();

// Recent picks
$recentPicks = $pdo->query("SELECT * FROM pick ORDER BY date_time DESC LIMIT 10")->fetchAll();

// Recent receives
$recentReceive = $pdo->query("SELECT * FROM receive ORDER BY created_at DESC LIMIT 10")->fetchAll();

jsonResponse([
    'total_receive' => $totalReceive,
    'open_items' => $openItems,
    'picked_items' => $pickedItems,
    'today_picks' => $todayPicks,
    'month_picks' => $monthPicks,
    'pass_count' => $passCount,
    'fail_count' => $failCount,
    'recent_picks' => $recentPicks,
    'recent_receive' => $recentReceive,
]);
