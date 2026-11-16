<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['director']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$scoreId = filter_var($input['score_id'] ?? null, FILTER_VALIDATE_INT);
if (!$scoreId) json(['error' => 'A valid score_id is required.'], 422);
$pdo = db();
$update = $pdo->prepare("UPDATE scores SET status = 'published', published_by = ? WHERE id = ? AND status = 'verified'");
$update->execute([$user['id'], $scoreId]);
if (!$update->rowCount()) json(['error' => 'Only verified scores can be published.'], 409);
audit($pdo, (int) $user['id'], 'score_publish', 'score', $scoreId);
json(['ok' => true]);