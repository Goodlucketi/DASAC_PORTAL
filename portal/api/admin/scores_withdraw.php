<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['director']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$scoreId = filter_var($input['score_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($input['reason'] ?? ''));
if (!$scoreId || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
    json(['error' => 'A valid score and withdrawal reason (5-1000 characters) are required.'], 422);
}
$pdo = db();
$update = $pdo->prepare("UPDATE scores SET status = 'verified', published_by = NULL WHERE id = ? AND status = 'published'");
$update->execute([$scoreId]);
if (!$update->rowCount()) json(['error' => 'Only published scores can be withdrawn.'], 409);
 audit($pdo, (int) $user['id'], 'score_withdraw', 'score', $scoreId, ['reason' => $reason]);
json(['ok' => true]);