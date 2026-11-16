<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student']);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);
$statement = $pdo->prepare("SELECT c.code, c.title AS course, sc.test_score AS test, sc.exam_score AS exam, sc.test_score + sc.exam_score AS total FROM scores sc JOIN courses c ON c.id = sc.course_id WHERE sc.student_id = ? AND sc.status = 'published' ORDER BY c.code");
$statement->execute([$sid]);
json(['scores' => $statement->fetchAll(), 'passMark' => 50]);