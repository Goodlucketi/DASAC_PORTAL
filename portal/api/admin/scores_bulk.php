<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
if (!$courseId || !isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK || (int) $_FILES['csv']['size'] > 2097152) json(['error' => 'Choose a course and CSV file under 2 MB.'], 422);
$file = $_FILES['csv'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv' || !in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) json(['error' => 'Only CSV files are accepted.'], 422);
$pdo = db();
$course = requireCourseScope($pdo, $user, $courseId);
$stream = fopen($file['tmp_name'], 'rb');
if (!$stream) json(['error' => 'Could not read the CSV file.'], 422);
$header = fgetcsv($stream);
$expected = ['matric_no', 'test_score', 'exam_score'];
if (!$header || array_map(static fn($value) => strtolower(trim((string) $value)), $header) !== $expected) {
    fclose($stream);
    json(['error' => 'CSV header must be: matric_no,test_score,exam_score'], 422);
}
$rows = [];
$line = 1;
$errors = [];
$learnerLookup = $pdo->prepare("SELECT s.id FROM students s JOIN enrollments e ON e.student_id = s.id WHERE s.matric_no = ? AND e.course_id = ? AND e.status = 'active'");
while (($values = fgetcsv($stream)) !== false) {
    $line++;
    if (count($values) !== 3) { $errors[] = "Line {$line}: expected 3 columns."; continue; }
    [$matric, $testRaw, $examRaw] = array_map(static fn($value) => trim((string) $value), $values);
    if ($matric === '' || !is_numeric($testRaw) || !is_numeric($examRaw)) { $errors[] = "Line {$line}: invalid matric number or scores."; continue; }
    $test = (float) $testRaw;
    $exam = (float) $examRaw;
    if ($test < 0 || $test > (float) $course['test_max'] || $exam < 0 || $exam > (float) $course['exam_max']) { $errors[] = "Line {$line}: score exceeds course maximum."; continue; }
    $learnerLookup->execute([$matric, $courseId]);
    $studentRecordId = $learnerLookup->fetchColumn();
    if (!$studentRecordId) { $errors[] = "Line {$line}: learner is not enrolled in this course."; continue; }
    $rows[] = [(int) $studentRecordId, $test, $exam];
}
fclose($stream);
if (!$rows || $errors) json(['error' => 'CSV was not imported; correct the listed rows and retry.', 'details' => $errors], 422);
$existingQuery = $pdo->prepare('SELECT status FROM scores WHERE student_id = ? AND course_id = ?');
$save = $pdo->prepare('INSERT INTO scores (student_id, course_id, test_score, exam_score, status, entered_by) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE test_score = IF(status = ?, VALUES(test_score), test_score), exam_score = IF(status = ?, VALUES(exam_score), exam_score), entered_by = IF(status = ?, VALUES(entered_by), entered_by)');
$conflicts = [];
$pdo->beginTransaction();
try {
    foreach ($rows as [$studentRecordId, $test, $exam]) {
        $existingQuery->execute([$studentRecordId, $courseId]);
        $status = $existingQuery->fetchColumn();
        if ($status && $status !== 'draft') { $conflicts[] = (string) $studentRecordId; continue; }
        $save->execute([$studentRecordId, $courseId, $test, $exam, 'draft', $user['id'], 'draft', 'draft', 'draft']);
    }
    if ($conflicts) {
        $pdo->rollBack();
        json(['error' => 'CSV was not imported because some scores are already verified or published.', 'student_ids' => $conflicts], 409);
    }
    audit($pdo, (int) $user['id'], 'score_bulk_import', 'course', $courseId, ['rows' => count($rows)]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true, 'imported' => count($rows)]);