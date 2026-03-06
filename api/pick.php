<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // List pick history
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? '';

        $sql = "SELECT * FROM pick WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (so LIKE ? OR serial LIKE ? OR model LIKE ? OR part_no LIKE ? OR name LIKE ?)";
            $s = "%$search%";
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
        }
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY date_time DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getInput();

        if (empty($data['so'])) {
            jsonResponse(['error' => 'กรุณาระบุเลข SO'], 400);
        }
        if (empty($data['serial'])) {
            jsonResponse(['error' => 'กรุณาสแกน Serial'], 400);
        }

        $userName = $_SESSION['user']['full_name'] ?? 'Unknown';

        // Check if serial already picked
        $dupCheck = $pdo->prepare("SELECT id, status FROM pick WHERE serial = ?");
        $dupCheck->execute([$data['serial']]);
        $existingPick = $dupCheck->fetch();
        if ($existingPick) {
            jsonResponse([
                'error' => "Serial '{$data['serial']}' ถูกเบิกไปแล้ว (สถานะ: {$existingPick['status']})",
            ], 409);
        }

        // Check if serial exists in receive table
        $check = $pdo->prepare("SELECT * FROM receive WHERE serial = ? AND status = 'Open'");
        $check->execute([$data['serial']]);
        $receiveItem = $check->fetch();

        $forceSubmit = $data['force'] ?? false;

        if ($receiveItem) {
            // Serial found — Pass
            $pdo->beginTransaction();
            try {
                // Insert pick record
                $stmt = $pdo->prepare("INSERT INTO pick (so, serial, model, part_no, date_time, status, name) VALUES (?, ?, ?, ?, NOW(), 'Pass', ?)");
                $stmt->execute([
                    $data['so'],
                    $data['serial'],
                    $receiveItem['model'],
                    $receiveItem['part_no'],
                    $userName,
                ]);

                // Update receive status
                $update = $pdo->prepare("UPDATE receive SET status='Confirm Picking', time_scan_out=NOW(), name_scan_out=? WHERE id=?");
                $update->execute([$userName, $receiveItem['id']]);

                $pdo->commit();

                jsonResponse([
                    'status' => 'Pass',
                    'message' => 'เบิกสำเร็จ',
                    'serial' => $data['serial'],
                    'model' => $receiveItem['model'],
                    'part_no' => $receiveItem['part_no'],
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                jsonResponse(['error' => $e->getMessage()], 500);
            }

        } else {
            // Serial NOT found
            if (!$forceSubmit) {
                // Return warning — ask user to confirm
                jsonResponse([
                    'status' => 'not_found',
                    'message' => "ไม่พบ Serial '{$data['serial']}' ในระบบ คุณต้องการเบิกหรือไม่?",
                    'serial' => $data['serial'],
                ], 200);
            } else {
                // Force submit — Fail
                $stmt = $pdo->prepare("INSERT INTO pick (so, serial, model, part_no, date_time, status, name) VALUES (?, ?, ?, ?, NOW(), 'Fail', ?)");
                $stmt->execute([
                    $data['so'],
                    $data['serial'],
                    $data['model'] ?? '',
                    $data['part_no'] ?? '',
                    $userName,
                ]);

                jsonResponse([
                    'status' => 'Fail',
                    'message' => 'บันทึกเป็น Fail — Serial ไม่มีในระบบ',
                    'serial' => $data['serial'],
                ]);
            }
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
