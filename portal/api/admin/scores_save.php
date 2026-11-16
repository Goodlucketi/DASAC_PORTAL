<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor', 'hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
$studentRecordId = filter_var($input['student_id'] ?? null, FILTER_VALIDATE_INT);
$test = filter_var($input['test_score'] ?? null, FILTER_VALIDATE_FLOAT);
$exam = filter_var($input['exam_score'] ?? null, FILTER_VALIDATE_FLOAT);
if (!$courseId || !$studentRecordId || $test === false || $exam === false) {
    json(['error' => 'Course, student, test score, and exam score are required.'], 422);
}
$pdo = db();
$course = requireCourseScope($pdo, $user, $courseId);
if ($test < 0 || $test > (float) $course['test_max'] || $exam < 0 || $exam > (float) $course['exam_max']) {
    json(['error' => 'Scores exceed the allowed course maximums.'], 422);
}
$enrollment = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? AND status = 'active'");
$enrollment->execute([$studentRecordId, $courseId]);
if (!$enrollment->fetchColumn()) {
    json(['error' => 'The student is not actively enrolled in this course.'], 422);
}
$existing = $pdo->prepare('SELECT id, status FROM scores WHERE student_id = ? AND course_id = ?');
$existing->execute([$studentRecordId, $courseId]);
$score = $existing->fetch();
if ($score && $score['status'] !== 'draft') {
    json(['error' => 'Only draft scores can be edited.'], 409);
}
if ($score) {
    $save = $pdo->prepare('UPDATE scores SET test_score = ?, exam_score = ?, entered_by = ? WHERE id = ? AND status = ?');
    $save->execute([$test, $exam, $user['id'], $score['id'], 'draft']);
    $scoreId = (int) $score['id'];
} else {
    $save = $pdo->prepare('INSERT INTO scores (student_id, course_id, test_score, exam_score, status, entered_by) VALUES (?, ?, ?, ?, ?, ?)');
    $save->execute([$studentRecordId, $courseId, $test, $exam, 'draft', $user['id']]);
    $scoreId = (int) $pdo->lastInsertId();
}
audit($pdo, (int) $user['id'], 'score_save', 'score', $scoreId, ['course_id' => $courseId]);
json(['ok' => true, 'score_id' => $scoreId]);