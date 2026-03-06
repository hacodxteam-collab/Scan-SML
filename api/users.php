<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// Only admin can manage users
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    jsonResponse(['error' => 'ไม่มีสิทธิ์เข้าถึง'], 403);
}

switch ($method) {
    case 'GET':
        // List all users (without passwords)
        $stmt = $pdo->query("SELECT id, username, full_name, department, role, created_at FROM users ORDER BY created_at DESC");
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $data = getInput();

        if (empty($data['username']) || empty($data['password']) || empty($data['full_name'])) {
            jsonResponse(['error' => 'กรุณากรอก Username, Password, และชื่อ'], 400);
        }

        // Check duplicate username
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$data['username']]);
        if ($check->fetch()) {
            jsonResponse(['error' => "Username '{$data['username']}' มีอยู่แล้ว"], 409);
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, department, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['username'],
            $hash,
            $data['full_name'],
            $data['department'] ?? '',
            $data['role'] ?? 'warehouse',
        ]);

        jsonResponse(['id' => $pdo->lastInsertId(), 'message' => 'เพิ่มสมาชิกสำเร็จ'], 201);
        break;

    case 'PUT':
        $data = getInput();
        if (empty($data['id'])) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        // Update with or without password change
        if (!empty($data['password'])) {
            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, full_name=?, department=?, role=? WHERE id=?");
            $stmt->execute([
                $data['username'] ?? '',
                $hash,
                $data['full_name'] ?? '',
                $data['department'] ?? '',
                $data['role'] ?? 'warehouse',
                $data['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, department=?, role=? WHERE id=?");
            $stmt->execute([
                $data['username'] ?? '',
                $data['full_name'] ?? '',
                $data['department'] ?? '',
                $data['role'] ?? 'warehouse',
                $data['id'],
            ]);
        }

        jsonResponse(['message' => 'อัปเดตสมาชิกสำเร็จ']);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            jsonResponse(['error' => 'ID is required'], 400);
        }

        // Prevent deleting yourself
        if ($_SESSION['user']['id'] == $id) {
            jsonResponse(['error' => 'ไม่สามารถลบตัวเองได้'], 400);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        jsonResponse(['message' => 'ลบสมาชิกสำเร็จ']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
