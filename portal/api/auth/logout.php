<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$user = $_SESSION['user'] ?? null;
if (is_array($user) && !empty($user['id'])) {
    audit(db(), (int) $user['id'], 'logout', 'user', (int) $user['id']);
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
json(['ok' => true]);