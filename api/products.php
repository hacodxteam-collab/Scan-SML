<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $search = $_GET['search'] ?? '';
        $category = $_GET['category'] ?? '';

        $sql = "SELECT * FROM products WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY category, name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getInput();
        if (empty($data['name']) || empty($data['sku'])) {
            jsonResponse(['error' => 'Name and SKU are required'], 400);
        }

        $stmt = $pdo->prepare("INSERT INTO products (name, sku, category, stock, unit, min_stock) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['name'],
            $data['sku'],
            $data['category'] ?? '',
            $data['stock'] ?? 0,
            $data['unit'] ?? 'ชิ้น',
            $data['min_stock'] ?? 5,
        ]);

        jsonResponse(['id' => $pdo->lastInsertId(), 'message' => 'Product created'], 201);
        break;

    case 'PUT':
        $data = getInput();
        if (empty($data['id'])) {
            jsonResponse(['error' => 'Product ID is required'], 400);
        }

        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category=?, stock=?, unit=?, min_stock=? WHERE id=?");
        $stmt->execute([
            $data['name'],
            $data['sku'],
            $data['category'] ?? '',
            $data['stock'] ?? 0,
            $data['unit'] ?? 'ชิ้น',
            $data['min_stock'] ?? 5,
            $data['id'],
        ]);

        jsonResponse(['message' => 'Product updated']);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['error' => 'Product ID is required'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['message' => 'Product deleted']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
