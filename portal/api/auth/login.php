<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    json(['error' => 'Enter a valid email address and password.'], 422);
}

$pdo = db();
$statement = $pdo->prepare('SELECT id, email, password_hash, role, status, full_name FROM users WHERE email = ? LIMIT 1');
$statement->execute([$email]);
$account = $statement->fetch();
if (!$account || $account['status'] !== 'active' || !password_verify($password, $account['password_hash'])) {
    json(['error' => 'Email or password is incorrect.'], 401);
}

session_regenerate_id(true);
$_SESSION['user'] = [
    'id' => (int) $account['id'],
    'email' => $account['email'],
    'role' => $account['role'],
    'name' => $account['full_name'],
];
 audit($pdo, (int) $account['id'], 'login', 'user', (int) $account['id']);
json(['user' => $_SESSION['user']]);