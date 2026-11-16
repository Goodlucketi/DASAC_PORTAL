<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor', 'hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$pdo = db();
$staff = staffId($pdo, (int) $user['id']);
$departmentId = (int) ($input['department_id'] ?? 0);
if ($user['role'] === 'hod') {
    $department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
    $department->execute([$user['id']]);
    $departmentId = (int) $department->fetchColumn();
}
$code = strtoupper(trim((string) ($input['code'] ?? '')));
$title = trim((string) ($input['title'] ?? ''));
if (!$departmentId || $code === '' || mb_strlen($code) > 30 || $title === '' || mb_strlen($title) > 180) {
    json(['error' => 'Enter a valid course code and title for your department.'], 422);
}
$existing = $pdo->prepare('SELECT id FROM courses WHERE department_id = ? AND code = ? LIMIT 1');
$existing->execute([$departmentId, $code]);
if ($existing->fetchColumn()) {
    json(['error' => 'A course with that code already exists in this department.'], 409);
}
$insert = $pdo->prepare('INSERT INTO courses (department_id, instructor_id, code, title, active) VALUES (?, ?, ?, ?, 1)');
$insert->execute([$departmentId, $staff, $code, $title]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'instructor_course_create', 'course', $id, ['department_id' => $departmentId, 'code' => $code]);
json(['ok' => true, 'id' => $id], 201);
