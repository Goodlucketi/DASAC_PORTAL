<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$attemptId = filter_var($input['attempt_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($input['reason'] ?? ''));
if (!$attemptId || mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) json(['error' => 'An attempt and review reason of at least 5 characters are required.'], 422);
$pdo = db();
$department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
$department->execute([$user['id']]);
$update = $pdo->prepare('UPDATE attendance_attempts aa JOIN attendance_sessions a ON a.id = aa.session_id JOIN courses c ON c.id = a.course_id SET aa.reviewed_by = ?, aa.reviewed_at = NOW(), aa.review_reason = ? WHERE aa.id = ? AND c.department_id = ? AND aa.reviewed_at IS NULL');
$update->execute([$user['id'], $reason, $attemptId, $department->fetchColumn()]);
if (!$update->rowCount()) json(['error' => 'Unreviewed attendance flag was not found in your department.'], 404);
audit($pdo, (int) $user['id'], 'attendance_attempt_review', 'attendance_attempt', $attemptId, ['reason' => $reason]);
json(['ok' => true]);