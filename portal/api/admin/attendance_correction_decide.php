<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$correctionId = filter_var($input['correction_id'] ?? null, FILTER_VALIDATE_INT);
$decision = (string) ($input['decision'] ?? '');
$reason = trim((string) ($input['reason'] ?? ''));
if (!$correctionId || !in_array($decision, ['approve', 'reject'], true) || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) json(['error' => 'A valid correction decision and reason (5 to 1000 characters) are required.'], 422);
$pdo = db();
$department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
$department->execute([$user['id']]);
$departmentId = (int) $department->fetchColumn();
$pdo->beginTransaction();
try {
    $lookup = $pdo->prepare('SELECT ac.*, a.course_id FROM attendance_corrections ac JOIN attendance_sessions a ON a.id = ac.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = ac.student_id WHERE ac.id = ? AND ac.status = ? AND c.department_id = ? FOR UPDATE');
    $lookup->execute([$correctionId, 'pending', $departmentId]);
    $correction = $lookup->fetch();
    if (!$correction) {
        $pdo->rollBack();
        json(['error' => 'Pending correction was not found in your department.'], 404);
    }
    $status = $decision === 'approve' ? 'approved' : 'rejected';
    if ($decision === 'approve') {
        $record = $pdo->prepare('INSERT INTO attendance_records (session_id, student_id, checked_in_at, distance_m, status) SELECT ?, ?, a.starts_at, 0, ? FROM attendance_sessions a WHERE a.id = ? ON DUPLICATE KEY UPDATE status = VALUES(status)');
        $record->execute([$correction['session_id'], $correction['student_id'], $correction['requested_status'], $correction['session_id']]);
    }
    $update = $pdo->prepare('UPDATE attendance_corrections SET status = ?, reviewed_by = ?, review_reason = ? WHERE id = ? AND status = ?');
    $update->execute([$status, $user['id'], $reason, $correctionId, 'pending']);
    audit($pdo, (int) $user['id'], 'attendance_correction_' . $decision, 'attendance_correction', $correctionId, ['reason' => $reason]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true]);