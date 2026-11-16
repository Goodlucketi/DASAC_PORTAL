<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor', 'hod', 'director']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$transferId = filter_var($input['transfer_id'] ?? null, FILTER_VALIDATE_INT);
$decision = (string) ($input['decision'] ?? '');
$comment = trim((string) ($input['reason'] ?? $input['comment'] ?? ''));
if (!$transferId || !in_array($decision, ['approve', 'reject', 'return'], true) || mb_strlen($comment) < 5 || mb_strlen($comment) > 1000) {
    json(['error' => 'A transfer decision and reason (5 to 1000 characters) are required.'], 422);
}
$pdo = db();
$lookup = $pdo->prepare('SELECT tr.*, cp.department_id AS target_department_id FROM transfer_requests tr JOIN programs cp ON cp.id = tr.target_program_id WHERE tr.id = ?');
$lookup->execute([$transferId]);
$transfer = $lookup->fetch();
if (!$transfer) json(['error' => 'Transfer request not found.'], 404);
$stages = [
    'pending_instructor' => ['stage' => 'instructor', 'roles' => ['instructor'], 'next' => 'pending_current_hod'],
    'pending_current_hod' => ['stage' => 'current_hod', 'roles' => ['hod'], 'next' => 'pending_target_hod'],
    'pending_target_hod' => ['stage' => 'target_hod', 'roles' => ['hod'], 'next' => 'pending_director'],
    'pending_director' => ['stage' => 'director', 'roles' => ['director'], 'next' => 'approved'],
];
$stage = $stages[$transfer['status']] ?? null;
if (!$stage || (!in_array($user['role'], $stage['roles'], true) && $user['role'] !== 'superadmin')) {
    json(['error' => 'This request is not awaiting your role.'], 403);
}
if ($user['role'] === 'instructor' || $user['role'] === 'hod') {
    $staffQuery = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
    $staffQuery->execute([$user['id']]);
    $departmentId = (int) $staffQuery->fetchColumn();
    $requiredDepartment = $stage['stage'] === 'target_hod' ? (int) $transfer['target_department_id'] : (int) $transfer['current_department_id'];
    $staffIdQuery = $pdo->prepare('SELECT id FROM staff WHERE user_id = ?');
    $staffIdQuery->execute([$user['id']]);
    $staffId = (int) $staffIdQuery->fetchColumn();
    if (!$departmentId || $departmentId !== $requiredDepartment) {
        json(['error' => 'This transfer is outside your department.'], 403);
    }
    if ($stage['stage'] === 'instructor' && (int) $transfer['instructor_id'] !== $staffId) {
        json(['error' => 'This transfer is assigned to another instructor.'], 403);
    }
}
if ($user['role'] === 'hod' && $stage['stage'] === 'target_hod' && $decision === 'approve') {
    $prerequisites = $pdo->prepare("SELECT COUNT(*) FROM program_prerequisites pp WHERE pp.program_id = ? AND NOT EXISTS (SELECT 1 FROM scores sc WHERE sc.student_id = ? AND sc.course_id = pp.course_id AND sc.status = 'published' AND sc.test_score + sc.exam_score >= 50)");
    $prerequisites->execute([$transfer['target_program_id'], $transfer['student_id']]);
    if ((int) $prerequisites->fetchColumn() > 0) json(['error' => 'The student has not completed all target-program prerequisites.'], 422);
}
$newStatus = match ($decision) {
    'reject' => 'rejected',
    'return' => 'returned',
    default => $stage['next'],
};
$pdo->beginTransaction();
try {
    $review = $pdo->prepare('INSERT INTO transfer_reviews (transfer_id, reviewer_id, stage, decision, comment) VALUES (?, ?, ?, ?, ?)');
    $review->execute([$transferId, $user['id'], $stage['stage'], $decision === 'approve' ? 'approved' : ($decision === 'reject' ? 'rejected' : 'returned'), $comment]);
    $update = $pdo->prepare('UPDATE transfer_requests SET status = ? WHERE id = ? AND status = ?');
    $update->execute([$newStatus, $transferId, $transfer['status']]);
    if (!$update->rowCount()) {
        $pdo->rollBack();
        json(['error' => 'The transfer status changed. Reload and try again.'], 409);
    }
    audit($pdo, (int) $user['id'], 'transfer_' . $decision, 'transfer_request', $transferId, ['stage' => $stage['stage']]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true, 'status' => $newStatus]);