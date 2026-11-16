<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$amendmentId = filter_var($input['amendment_id'] ?? null, FILTER_VALIDATE_INT);
$decision = (string) ($input['decision'] ?? '');
$reason = trim((string) ($input['reason'] ?? ''));
if (!$amendmentId || !in_array($decision, ['approve', 'reject'], true) || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) json(['error' => 'Choose an amendment decision and enter a reason between 5 and 1000 characters.'], 422);
$pdo = db();
$department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
$department->execute([$user['id']]);
$departmentId = (int) $department->fetchColumn();
$pdo->beginTransaction();
try {
    $lookup = $pdo->prepare('SELECT sa.*, sc.course_id FROM score_amendments sa JOIN scores sc ON sc.id = sa.score_id JOIN courses c ON c.id = sc.course_id WHERE sa.id = ? AND sa.status = ? AND c.department_id = ? FOR UPDATE');
    $lookup->execute([$amendmentId, 'pending', $departmentId]);
    $amendment = $lookup->fetch();
    if (!$amendment) {
        $pdo->rollBack();
        json(['error' => 'Pending amendment was not found in your department.'], 404);
    }
    if ($decision === 'approve') {
        $updateScore = $pdo->prepare('UPDATE scores SET test_score = ?, exam_score = ? WHERE id = ?');
        $updateScore->execute([$amendment['test_score'], $amendment['exam_score'], $amendment['score_id']]);
    }
    $status = $decision === 'approve' ? 'approved' : 'rejected';
    $update = $pdo->prepare('UPDATE score_amendments SET status = ?, reviewed_by = ?, review_reason = ?, reviewed_at = NOW() WHERE id = ? AND status = ?');
    $update->execute([$status, $user['id'], $reason, $amendmentId, 'pending']);
    audit($pdo, (int) $user['id'], 'score_amendment_' . $decision, 'score_amendment', $amendmentId, ['reason' => $reason]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true, 'status' => $status]);