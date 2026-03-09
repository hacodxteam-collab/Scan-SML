<?php
require_once __DIR__ . '/config.php';

$serial = '10253A294597';

echo "<h3>Pick Table for $serial</h3>";
$picks = $pdo->prepare("SELECT * FROM pick WHERE serial = ?");
$picks->execute([$serial]);
echo "<pre>";
print_r($picks->fetchAll());
echo "</pre>";

echo "<h3>Receive Table for $serial</h3>";
$recs = $pdo->prepare("SELECT * FROM receive WHERE serial = ?");
$recs->execute([$serial]);
echo "<pre>";
print_r($recs->fetchAll());
echo "</pre>";
