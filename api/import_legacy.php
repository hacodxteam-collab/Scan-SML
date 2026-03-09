<?php

/**
 * One-time Import Script: ScanWarehouse.xlsx -> Database
 * Reads legacy data from Excel and imports into receive & pick tables
 * 
 * Usage: Open in browser: http://192.168.60.86/scan-sml/api/import_legacy.php
 */

require_once 'config.php';

// Override JSON content type from config.php
header('Content-Type: text/html; charset=utf-8');

// ============ XLSX Reader (using ZipArchive) ============

function readXlsx($filePath)
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['error' => "Cannot open file: $filePath"];
    }

    // Read shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ssDoc = new DOMDocument();
        $ssDoc->loadXML($ssXml);
        $siNodes = $ssDoc->getElementsByTagName('si');
        foreach ($siNodes as $si) {
            $text = '';
            $tNodes = $si->getElementsByTagName('t');
            foreach ($tNodes as $t) {
                $text .= $t->textContent;
            }
            $sharedStrings[] = $text;
        }
    }

    // Read workbook to get sheet names
    $sheets = [];
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml) {
        $wbDoc = new DOMDocument();
        $wbDoc->loadXML($wbXml);
        $sheetNodes = $wbDoc->getElementsByTagName('sheet');
        $idx = 1;
        foreach ($sheetNodes as $sheet) {
            $sheets[$sheet->getAttribute('name')] = $idx;
            $idx++;
        }
    }

    $result = [];

    foreach ($sheets as $sheetName => $sheetIdx) {
        $sheetXml = $zip->getFromName("xl/worksheets/sheet{$sheetIdx}.xml");
        if (!$sheetXml) continue;

        $doc = new DOMDocument();
        $doc->loadXML($sheetXml);
        $rows = $doc->getElementsByTagName('row');

        $data = [];
        foreach ($rows as $row) {
            $rowData = [];
            $cells = $row->getElementsByTagName('c');
            foreach ($cells as $cell) {
                $ref = $cell->getAttribute('r');
                // Extract column letter
                preg_match('/^([A-Z]+)/', $ref, $matches);
                $col = $matches[1];
                $colIdx = 0;
                for ($i = 0; $i < strlen($col); $i++) {
                    $colIdx = $colIdx * 26 + (ord($col[$i]) - ord('A'));
                }

                $vNode = $cell->getElementsByTagName('v');
                $value = $vNode->length > 0 ? $vNode->item(0)->textContent : '';

                // Check if it's a shared string
                if ($cell->getAttribute('t') === 's') {
                    $value = isset($sharedStrings[(int)$value]) ? $sharedStrings[(int)$value] : $value;
                }

                $rowData[$colIdx] = $value;
            }
            $data[] = $rowData;
        }

        $result[$sheetName] = $data;
    }

    $zip->close();
    return $result;
}

// ============ Date Parser ============

function parseDate($dateStr)
{
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);

    // Try various formats
    $formats = [
        'd-m-Y H:i',
        'd-m-Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y H:i:s',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd-m-Y',
        'd m Y H:i',
        'd-m Y H:i',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $dateStr);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }

    // Check if it's an Excel serial number (numeric)
    if (is_numeric($dateStr)) {
        $unix = ($dateStr - 25569) * 86400;
        if ($unix > 0) {
            return date('Y-m-d H:i:s', (int)$unix);
        }
    }

    return null;
}

// ============ Main Import ============

$filePath = __DIR__ . '/../ScanWarehouse.xlsx';

if (!file_exists($filePath)) {
    jsonResponse(['error' => 'File not found: ScanWarehouse.xlsx'], 404);
}

$data = readXlsx($filePath);

if (isset($data['error'])) {
    jsonResponse(['error' => $data['error']], 500);
}

$results = ['receive' => 0, 'pick' => 0, 'receive_skipped' => 0, 'pick_skipped' => 0, 'errors' => []];

// ===== Clear existing data (keep users) =====
$pdo->exec("TRUNCATE TABLE `pick`");
$pdo->exec("TRUNCATE TABLE `receive`");
$results['cleared'] = true;

// ===== Import Receive Sheet =====
if (isset($data['Receive'])) {
    $rows = $data['Receive'];
    // Skip header row (row 0)
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $po      = trim($row[0] ?? '');
        $model   = trim($row[1] ?? '');
        $partNo  = trim($row[2] ?? '');
        $serial  = trim($row[3] ?? '');
        $warranty = trim($row[4] ?? '');
        // col 5 = Type (skip)
        $status  = trim($row[6] ?? 'Open');
        $scanIn  = parseDate($row[7] ?? '');
        $scanOut = parseDate($row[8] ?? '');
        $name    = trim($row[9] ?? '');

        if (empty($serial)) continue;

        // Remove "Years"/"Year" from warranty, keep number only
        $warranty = preg_replace('/\s*(Years?|years?)\s*/', '', $warranty);
        $warranty = trim($warranty);

        try {
            $stmt = $pdo->prepare("INSERT INTO receive (po, model, part_no, serial, warranty, status, time_scan_in, name_scan_in, time_scan_out, name_scan_out)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE po=VALUES(po), model=VALUES(model), part_no=VALUES(part_no), warranty=VALUES(warranty), status=VALUES(status), time_scan_in=VALUES(time_scan_in), name_scan_in=VALUES(name_scan_in), time_scan_out=VALUES(time_scan_out), name_scan_out=VALUES(name_scan_out)");
            $stmt->execute([$po, $model, $partNo, $serial, $warranty, $status, $scanIn, $name, $scanOut, $name]);
            $results['receive']++;
        } catch (PDOException $e) {
            $results['errors'][] = "Receive row $i: " . $e->getMessage();
            $results['receive_skipped']++;
        }
    }
}

// ===== Import Pick Sheet =====
if (isset($data['Pick'])) {
    $rows = $data['Pick'];
    // Skip header row (row 0)
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $so      = trim($row[0] ?? '');
        $serial  = trim($row[1] ?? '');
        $model   = trim($row[2] ?? '');
        $partNo  = trim($row[3] ?? '');
        $dateTime = parseDate($row[4] ?? '');
        $status  = trim($row[5] ?? 'Pass');
        $name    = trim($row[6] ?? '');

        if (empty($serial) && empty($so)) continue;
        if (!$dateTime) $dateTime = date('Y-m-d H:i:s');

        // Normalize status
        $status = (strtolower($status) === 'pass' || strtolower($status) === 'PASS') ? 'Pass' : 'Fail';

        try {
            $stmt = $pdo->prepare("INSERT INTO pick (so, serial, model, part_no, date_time, status, name)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$so, $serial, $model, $partNo, $dateTime, $status, $name]);
            $results['pick']++;

            // Sync with receive table: if picked as Pass, mark the receive item as Confirm Picking
            if ($status === 'Pass' && !empty($serial)) {
                $updRes = $pdo->prepare("UPDATE receive SET status = 'Confirm Picking', time_scan_out = ?, name_scan_out = ? WHERE serial = ?");
                $updRes->execute([$dateTime, $name, $serial]);
            }
        } catch (PDOException $e) {
            $results['errors'][] = "Pick row $i: " . $e->getMessage();
            $results['pick_skipped']++;
        }
    }
}

// Return results
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Import Result</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #1a1a2e;
            font-size: 22px;
            margin-bottom: 20px;
        }

        .stat {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .stat:last-child {
            border-bottom: none;
        }

        .label {
            color: #666;
        }

        .value {
            font-weight: 700;
        }

        .success {
            color: #16a34a;
        }

        .warning {
            color: #dc2626;
        }

        .errors {
            margin-top: 16px;
            font-size: 12px;
            color: #dc2626;
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>📦 Import Legacy Data - Result</h1>
        <div class="stat">
            <span class="label">✅ Receive imported</span>
            <span class="value success"><?= $results['receive'] ?> rows</span>
        </div>
        <div class="stat">
            <span class="label">⚠️ Receive skipped</span>
            <span class="value warning"><?= $results['receive_skipped'] ?> rows</span>
        </div>
        <div class="stat">
            <span class="label">✅ Pick imported</span>
            <span class="value success"><?= $results['pick'] ?> rows</span>
        </div>
        <div class="stat">
            <span class="label">⚠️ Pick skipped</span>
            <span class="value warning"><?= $results['pick_skipped'] ?> rows</span>
        </div>
        <?php if (!empty($results['errors'])): ?>
            <div class="errors">
                <strong>Errors:</strong><br>
                <?php foreach (array_slice($results['errors'], 0, 20) as $err): ?>
                    <?= htmlspecialchars($err) ?><br>
                <?php endforeach; ?>
                <?php if (count($results['errors']) > 20): ?>
                    <em>...and <?= count($results['errors']) - 20 ?> more</em>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>