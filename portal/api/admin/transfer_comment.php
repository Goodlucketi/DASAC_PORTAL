<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$transferId = filter_var($input['transfer_id'] ?? null, FILTER_VALIDATE_INT);
$comment = trim((string) ($input['comment'] ?? ''));
if (!$transferId || mb_strlen($comment) < 5 || mb_strlen($comment) > 2000) json(['error' => 'Enter a comment between 5 and 2000 characters.'], 422);
$pdo = db();
$staff = staffId($pdo, (int) $user['id']);
$lookup = $pdo->prepare('SELECT id FROM transfer_requests WHERE id = ? AND instructor_id = ? AND status = ?');
$lookup->execute([$transferId, $staff, 'pending_instructor']);
if (!$lookup->fetchColumn()) json(['error' => 'This transfer is not assigned to your classes.'], 403);
$insert = $pdo->prepare('INSERT INTO transfer_reviews (transfer_id, reviewer_id, stage, decision, comment) VALUES (?, ?, ?, ?, ?)');
$insert->execute([$transferId, $user['id'], 'instructor', 'comment', $comment]);
audit($pdo, (int) $user['id'], 'transfer_comment', 'transfer_request', $transferId);
json(['ok' => true]);