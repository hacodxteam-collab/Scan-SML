<?php
require_once __DIR__ . '/config.php';

// Auto-append "Years" if warranty is just a number
function formatWarranty($val) {
    $val = trim($val);
    if ($val === '') return '';
    if (is_numeric($val)) return $val . ' Years';
    return $val;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // List/search receive items
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';

        $sql = "SELECT * FROM receive WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (po LIKE ? OR model LIKE ? OR part_no LIKE ? OR serial LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getInput();
        $action = $data['action'] ?? 'create';

        if ($action === 'create') {
            // Single item receive
            if (empty($data['serial'])) {
                jsonResponse(['error' => 'Serial number is required'], 400);
            }

            // Check duplicate serial
            $check = $pdo->prepare("SELECT id FROM receive WHERE serial = ?");
            $check->execute([$data['serial']]);
            if ($check->fetch()) {
                jsonResponse(['error' => "Serial '{$data['serial']}' มีอยู่ในระบบแล้ว"], 409);
            }

            $userName = $_SESSION['user']['full_name'] ?? 'Unknown';

            $stmt = $pdo->prepare("INSERT INTO receive (po, model, part_no, serial, warranty, status, time_scan_in, name_scan_in) VALUES (?, ?, ?, ?, ?, 'Open', NOW(), ?)");
            $stmt->execute([
                $data['po'] ?? '',
                $data['model'] ?? '',
                $data['part_no'] ?? '',
                $data['serial'],
                formatWarranty($data['warranty'] ?? ''),
                $userName,
            ]);

            jsonResponse(['id' => $pdo->lastInsertId(), 'message' => 'รับสินค้าเข้าสำเร็จ'], 201);

        } elseif ($action === 'import') {
            // Excel import — expects array of items
            if (empty($data['items']) || !is_array($data['items'])) {
                jsonResponse(['error' => 'Items array is required'], 400);
            }

            $userName = $_SESSION['user']['full_name'] ?? 'Unknown';
            $imported = 0;
            $skipped = 0;
            $errors = [];

            $checkStmt = $pdo->prepare("SELECT id FROM receive WHERE serial = ?");
            $insertStmt = $pdo->prepare("INSERT INTO receive (po, model, part_no, serial, warranty, status, time_scan_in, name_scan_in) VALUES (?, ?, ?, ?, ?, 'Open', NOW(), ?)");

            foreach ($data['items'] as $i => $item) {
                if (empty($item['serial'])) {
                    $errors[] = "Row " . ($i + 1) . ": Serial is empty, skipped";
                    $skipped++;
                    continue;
                }

                $checkStmt->execute([$item['serial']]);
                if ($checkStmt->fetch()) {
                    $errors[] = "Row " . ($i + 1) . ": Serial '{$item['serial']}' duplicate, skipped";
                    $skipped++;
                    continue;
                }

                try {
                    $insertStmt->execute([
                        $item['po'] ?? '',
                        $item['model'] ?? '',
                        $item['part_no'] ?? '',
                        $item['serial'],
                        formatWarranty($item['warranty'] ?? ''),
                        $userName,
                    ]);
                    $imported++;
                } catch (Exception $e) {
                    $errors[] = "Row " . ($i + 1) . ": " . $e->getMessage();
                    $skipped++;
                }
            }

            jsonResponse([
                'message' => "Import สำเร็จ: $imported รายการ, ข้าม: $skipped รายการ",
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ]);
        }
        break;

    case 'PUT':
        // Update receive item
        $data = getInput();
        if (empty($data['id'])) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        $stmt = $pdo->prepare("UPDATE receive SET po=?, model=?, part_no=?, serial=?, warranty=? WHERE id=?");
        $stmt->execute([
            $data['po'] ?? '',
            $data['model'] ?? '',
            $data['part_no'] ?? '',
            $data['serial'] ?? '',
            formatWarranty($data['warranty'] ?? ''),
            $data['id'],
        ]);

        jsonResponse(['message' => 'อัปเดตสำเร็จ']);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM receive WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['message' => 'ลบสำเร็จ']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
