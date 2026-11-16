<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student', 'instructor', 'hod', 'director', 'superadmin']);
$statement = db()->prepare('SELECT id, body AS text, created_at AS date, read_at IS NULL AS unread FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
$statement->execute([$user['id']]);
json(['notifications' => $statement->fetchAll()]);