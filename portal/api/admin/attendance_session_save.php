<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
$geofenceId = filter_var($input['geofence_id'] ?? null, FILTER_VALIDATE_INT);
$title = trim((string) ($input['title'] ?? ''));
$start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) ($input['starts_at'] ?? ''));
$end = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) ($input['ends_at'] ?? ''));
if ($title === '' || mb_strlen($title) > 180 || !$start || !$end || $end <= $start || $end->getTimestamp() - $start->getTimestamp() > 86400 || $end < new DateTimeImmutable()) {
    json(['error' => 'Enter a valid session title, start time, end time, and a session window no longer than 24 hours.'], 422);
}
$pdo = db();
$staff = staffId($pdo, (int) $user['id']);
$staffDept = $pdo->prepare('SELECT department_id FROM staff WHERE id = ?');
$staffDept->execute([$staff]);
$departmentId = (int) $staffDept->fetchColumn();
if (!$departmentId) json(['error' => 'Your department assignment is missing.'], 422);
if ($courseId) {
    $course = $pdo->prepare('SELECT id, department_id, instructor_id FROM courses WHERE id = ? AND active = 1');
    $course->execute([$courseId]);
    $courseRow = $course->fetch();
    if (!$courseRow || (int) $courseRow['department_id'] !== $departmentId || (int) $courseRow['instructor_id'] !== $staff) {
        json(['error' => 'This course is outside your department or teaching assignment.'], 403);
    }
} else {
    $defaultCourse = $pdo->prepare('SELECT id FROM courses WHERE department_id = ? AND active = 1 ORDER BY code LIMIT 1');
    $defaultCourse->execute([$departmentId]);
    $courseId = (int) $defaultCourse->fetchColumn();
    if (!$courseId) json(['error' => 'No active courses are available in your department yet.'], 422);
}
if ($geofenceId) {
    $geofence = $pdo->prepare('SELECT id FROM geofences WHERE id = ? AND active = 1');
    $geofence->execute([$geofenceId]);
    if (!$geofence->fetchColumn()) {
        json(['error' => 'The selected venue is unavailable.'], 422);
    }
} else {
    $defaultGeofence = $pdo->prepare('SELECT id FROM geofences WHERE active = 1 ORDER BY name LIMIT 1');
    $defaultGeofence->execute();
    $geofenceId = (int) $defaultGeofence->fetchColumn();
    if (!$geofenceId) json(['error' => 'No active geofence is configured.'], 422);
}
$status = $start <= new DateTimeImmutable() ? 'open' : 'scheduled';
$insert = $pdo->prepare('INSERT INTO attendance_sessions (course_id, geofence_id, created_by, assigned_to, title, starts_at, ends_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([$courseId, $geofenceId, $staff, $staff, $title, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $status]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'attendance_session_create', 'attendance_session', $id, ['course_id' => $courseId, 'department_id' => $departmentId, 'geofence_id' => $geofenceId]);
json(['ok' => true, 'id' => $id], 201);