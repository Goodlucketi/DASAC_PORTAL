<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$scoreId = filter_var($input['score_id'] ?? null, FILTER_VALIDATE_INT);
$decision = (string) ($input['decision'] ?? 'verify');
$reason = trim((string) ($input['reason'] ?? ''));
if (!$scoreId || !in_array($decision, ['verify', 'return'], true) || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) json(['error' => 'Choose a decision and enter a reason between 5 and 1000 characters.'], 422);
$pdo = db();
$lookup = $pdo->prepare('SELECT course_id FROM scores WHERE id = ?');
$lookup->execute([$scoreId]);
$courseId = $lookup->fetchColumn();
if (!$courseId) json(['error' => 'Score not found.'], 404);
requireCourseScope($pdo, $user, (int) $courseId);
$pdo->beginTransaction();
try {
    $review = $pdo->prepare('INSERT INTO score_reviews (score_id, reviewer_id, decision, reason) VALUES (?, ?, ?, ?)');
    $review->execute([$scoreId, $user['id'], $decision === 'verify' ? 'verified' : 'returned', $reason]);
    $status = $decision === 'verify' ? 'verified' : 'draft';
    $update = $pdo->prepare('UPDATE scores SET status = ?, verified_by = ? WHERE id = ? AND status = ?');
    $update->execute([$status, $decision === 'verify' ? $user['id'] : null, $scoreId, 'draft']);
    if (!$update->rowCount()) {
        $pdo->rollBack();
        json(['error' => 'Only draft scores can be reviewed.'], 409);
    }
    audit($pdo, (int) $user['id'], 'score_' . $decision, 'score', $scoreId, ['reason' => $reason]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true, 'status' => $status]);