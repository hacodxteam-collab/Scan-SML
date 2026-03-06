<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = getInput();
        $action = $data['action'] ?? '';

        if ($action === 'login') {
            if (empty($data['username']) || empty($data['password'])) {
                jsonResponse(['error' => 'กรุณากรอก Username และ Password'], 400);
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$data['username']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($data['password'], $user['password'])) {
                jsonResponse(['error' => 'Username หรือ Password ไม่ถูกต้อง'], 401);
            }

            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'department' => $user['department'],
                'role' => $user['role'],
            ];

            jsonResponse([
                'message' => 'Login successful',
                'user' => $_SESSION['user'],
            ]);

        } elseif ($action === 'logout') {
            session_destroy();
            jsonResponse(['message' => 'Logged out']);

        } elseif ($action === 'check') {
            if (isset($_SESSION['user'])) {
                jsonResponse(['logged_in' => true, 'user' => $_SESSION['user']]);
            } else {
                jsonResponse(['logged_in' => false], 401);
            }

        } else {
            jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
