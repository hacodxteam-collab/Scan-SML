<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

// This script finds all 'Pass' records in the pick table
// and updates the corresponding record in the receive table to 'Confirm Picking'
// if it is still marked as 'Open'.

try {
    $pdo->beginTransaction();

    // Find all 'Pass' serials in the pick table
    $stmt = $pdo->query("SELECT serial, date_time, name FROM pick WHERE status = 'Pass' AND serial != ''");
    $pickedItems = $stmt->fetchAll();

    $updatedCount = 0;

    $updateStmt = $pdo->prepare("UPDATE receive SET status = 'Confirm Picking', time_scan_out = ?, name_scan_out = ? WHERE serial = ? AND status = 'Open'");

    foreach ($pickedItems as $item) {
        $updateStmt->execute([
            $item['date_time'],
            $item['name'],
            $item['serial']
        ]);

        // rowCount() tells us if a row was actually changed
        if ($updateStmt->rowCount() > 0) {
            $updatedCount++;
        }
    }

    $pdo->commit();

    echo "<h1>Sync Complete</h1>";
    echo "<p>Successfully synced <strong>{$updatedCount}</strong> items from 'Open' to 'Confirm Picking' based on Pick History.</p>";
    echo "<p><a href='../admin.html'>Return to Admin Panel</a></p>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
