<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['director', 'superadmin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$title = trim((string) ($input['title'] ?? ''));
$body = trim((string) ($input['body'] ?? ''));
$pinned = !empty($input['pinned']) ? 1 : 0;
if ($title === '' || mb_strlen($title) > 180 || mb_strlen($body) < 10 || mb_strlen($body) > 5000) {
    json(['error' => 'Add a valid title and announcement body between 10 and 5000 characters.'], 422);
}
$pdo = db();
$insert = $pdo->prepare('INSERT INTO announcements (audience, posted_by, title, body, pinned, published_at, active) VALUES ("all", ?, ?, ?, ?, NOW(), 1)');
$insert->execute([$user['id'], $title, $body, $pinned]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'institution_announcement_create', 'announcement', $id, ['pinned' => (bool) $pinned]);
json(['ok' => true, 'id' => $id], 201);
