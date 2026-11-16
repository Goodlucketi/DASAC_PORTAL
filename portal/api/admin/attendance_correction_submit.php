<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
$studentRecordId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
$sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($input['reason'] ?? ''));
$requestedStatus = (string) ($input['requested_status'] ?? 'present');
if (!$courseId || !$studentRecordId || !$sessionId || mb_strlen($reason) < 8 || !in_array($requestedStatus, ['present', 'excused'], true)) {
    json(['error' => 'Select the student and session, give a reason of at least 8 characters, and choose a correction.'], 422);
}
$pdo = db();
$course = requireCourseScope($pdo, $user, $courseId);
$enrollment = $pdo->prepare("SELECT e.id FROM enrollments e JOIN attendance_sessions a ON a.course_id = e.course_id WHERE e.student_id = ? AND e.course_id = ? AND a.id = ? AND e.status = 'active'");
$enrollment->execute([$studentRecordId, $courseId, $sessionId]);
if (!$enrollment->fetchColumn()) json(['error' => 'The student or session is outside your course.'], 403);
$existing = $pdo->prepare("SELECT id FROM attendance_corrections WHERE student_id = ? AND session_id = ? AND status = 'pending'");
$existing->execute([$studentRecordId, $sessionId]);
if ($existing->fetchColumn()) json(['error' => 'A correction for this student and session is already pending.'], 409);
$insert = $pdo->prepare('INSERT INTO attendance_corrections (student_id, session_id, submitted_by, requested_status, explanation) VALUES (?, ?, ?, ?, ?)');
$insert->execute([$studentRecordId, $sessionId, $user['id'], $requestedStatus, $reason]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'attendance_correction_submit', 'attendance_correction', $id, ['course_id' => $courseId]);
json(['ok' => true, 'id' => $id], 201);