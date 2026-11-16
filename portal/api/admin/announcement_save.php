<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$courseId = filter_var($input['course_id'] ?? null, FILTER_VALIDATE_INT);
$title = trim((string) ($input['title'] ?? ''));
$body = trim((string) ($input['body'] ?? ''));
if (!$courseId || $title === '' || mb_strlen($title) > 180 || mb_strlen($body) < 10 || mb_strlen($body) > 5000) json(['error' => 'Choose a course, add a title, and enter an announcement of at least 10 characters.'], 422);
$pdo = db();
requireCourseScope($pdo, $user, $courseId);
$insert = $pdo->prepare('INSERT INTO announcements (course_id, posted_by, title, body, published_at) VALUES (?, ?, ?, ?, NOW())');
$insert->execute([$courseId, $user['id'], $title, $body]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'class_announcement_create', 'announcement', $id, ['course_id' => $courseId]);
json(['ok' => true, 'id' => $id], 201);