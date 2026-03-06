<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $search = $_GET['search'] ?? '';

        $sql = "SELECT * FROM requisitions WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (requester_name LIKE ? OR department LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $requisitions = $stmt->fetchAll();

        // Attach items to each requisition
        $itemStmt = $pdo->prepare("SELECT * FROM requisition_items WHERE requisition_id = ?");
        foreach ($requisitions as &$req) {
            $itemStmt->execute([$req['id']]);
            $req['items'] = $itemStmt->fetchAll();
        }

        jsonResponse($requisitions);
        break;

    case 'POST':
        $data = getInput();

        if (empty($data['requester_name']) || empty($data['items']) || !is_array($data['items'])) {
            jsonResponse(['error' => 'Requester name and items are required'], 400);
        }

        try {
            $pdo->beginTransaction();

            // Calculate total items
            $totalItems = 0;
            foreach ($data['items'] as $item) {
                $totalItems += $item['quantity'];
            }

            // Create requisition record
            $stmt = $pdo->prepare("INSERT INTO requisitions (requester_name, department, note, total_items) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $data['requester_name'],
                $data['department'] ?? '',
                $data['note'] ?? '',
                $totalItems,
            ]);
            $reqId = $pdo->lastInsertId();

            // Insert items and deduct stock
            $insertItem = $pdo->prepare("INSERT INTO requisition_items (requisition_id, product_id, product_name, quantity, unit) VALUES (?, ?, ?, ?, ?)");
            $deductStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

            foreach ($data['items'] as $item) {
                // Check stock
                $deductStock->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                if ($deductStock->rowCount() === 0) {
                    throw new Exception("สินค้า '{$item['product_name']}' มีจำนวนไม่เพียงพอ");
                }

                $insertItem->execute([
                    $reqId,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['unit'] ?? 'ชิ้น',
                ]);
            }

            $pdo->commit();
            jsonResponse(['id' => $reqId, 'message' => 'Requisition created'], 201);

        } catch (Exception $e) {
            $pdo->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
