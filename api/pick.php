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

        // Check if this is a RETRY action
        if (isset($_GET['action']) && $_GET['action'] === 'retry') {
            if (empty($data['pick_id'])) {
                jsonResponse(['error' => 'No pick_id provided'], 400);
            }

            // 1. Get the Failed Pick Record
            $pickStmt = $pdo->prepare("SELECT * FROM pick WHERE id = ? AND status = 'Fail'");
            $pickStmt->execute([$data['pick_id']]);
            $pick = $pickStmt->fetch();

            if (!$pick) {
                jsonResponse(['error' => 'ไม่พบรายการเบิกที่ Fail (อาจถูกลบหรือ Pass ไปแล้ว)'], 404);
            }

            // 2. Check if Serial exists in Receive at all
            $recStmt = $pdo->prepare("SELECT * FROM receive WHERE serial = ?");
            $recStmt->execute([$pick['serial']]);
            $receiveItem = $recStmt->fetch();

            if (!$receiveItem) {
                // Truly missing - trigger quick add
                jsonResponse([
                    'status' => 'not_found',
                    'message' => "Serial '{$pick['serial']}' ไม่มีระบบ",
                    'pick_data' => $pick
                ]);
            }

            // If it exists, check if it's Open
            if ($receiveItem['status'] !== 'Open') {
                jsonResponse([
                    'error' => "Serial '{$pick['serial']}' มีในระบบแล้ว แต่ถูกเบิกออกไปแล้ว (สถานะ: {$receiveItem['status']}) ไม่สามารถเบิกซ้ำได้"
                ], 400);
            }

            // 3. Update both tables
            $userName = $_SESSION['user']['full_name'] ?? 'Unknown';
            $pdo->beginTransaction();
            try {
                // Update Pick
                $updPick = $pdo->prepare("UPDATE pick SET status = 'Pass', model = ?, part_no = ?, name = ?, date_time = NOW() WHERE id = ?");
                $updPick->execute([$receiveItem['model'], $receiveItem['part_no'], $userName, $pick['id']]);

                // Update Receive
                $updRec = $pdo->prepare("UPDATE receive SET status = 'Confirm Picking', time_scan_out = NOW(), name_scan_out = ? WHERE id = ?");
                $updRec->execute([$userName, $receiveItem['id']]);

                $pdo->commit();
                jsonResponse(['status' => 'success', 'message' => 'ตรวจสอบและส่งเบิกใหม่ (Retry) สำเร็จ!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
            break;
        }

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
            $finalModel = !empty($receiveItem['model']) ? $receiveItem['model'] : ($data['model'] ?? '');
            $finalPartNo = !empty($receiveItem['part_no']) ? $receiveItem['part_no'] : ($data['part_no'] ?? '');

            $pdo->beginTransaction();
            try {
                // Insert pick record
                $stmt = $pdo->prepare("INSERT INTO pick (so, serial, model, part_no, date_time, status, name) VALUES (?, ?, ?, ?, NOW(), 'Pass', ?)");
                $stmt->execute([
                    $data['so'],
                    $data['serial'],
                    $finalModel,
                    $finalPartNo,
                    $userName,
                ]);

                // Update receive status
                $update = $pdo->prepare("UPDATE receive SET status='Confirm Picking', time_scan_out=NOW(), name_scan_out=? WHERE id=?");
                $update->execute([$userName, $receiveItem['id']]);

                $pdo->commit();

                jsonResponse([
                    'status' => 'Pass',
                    'message' => 'เบิกสำเร็จ',
                    'so' => $data['so'],
                    'serial' => $data['serial'],
                    'model' => $finalModel,
                    'part_no' => $finalPartNo,
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
                    'so' => $data['so'],
                    'serial' => $data['serial'],
                    'model' => $data['model'] ?? '',
                    'part_no' => $data['part_no'] ?? '',
                ]);
            }
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        try {
            // Optional: you could restrict this to only allow deleting "Fail" status records
            // but we'll leave it open for admin cleanup and handle permission in UI
            $stmt = $pdo->prepare("DELETE FROM pick WHERE id=?");
            $stmt->execute([$id]);
            jsonResponse(['message' => 'ลบรายการสำเร็จ']);
        } catch (PDOException $e) {
            jsonResponse(['error' => 'Delete failed: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
