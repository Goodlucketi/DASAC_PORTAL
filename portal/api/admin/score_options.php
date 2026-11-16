<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor', 'hod']);
$pdo = db();
if ($user['role'] === 'instructor') {
    $courses = $pdo->prepare('SELECT c.id, c.code, c.title, c.test_max AS testMax, c.exam_max AS examMax FROM courses c WHERE c.instructor_id = (SELECT id FROM staff WHERE user_id = ?) AND c.active = 1 ORDER BY c.code');
    $courses->execute([$user['id']]);
    $learners = $pdo->prepare("SELECT DISTINCT s.id, u.full_name AS name, e.course_id AS courseId FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN students s ON s.id = e.student_id JOIN users u ON u.id = s.user_id WHERE c.instructor_id = (SELECT id FROM staff WHERE user_id = ?) AND c.active = 1 AND e.status = 'active' ORDER BY c.code, u.full_name");
    $learners->execute([$user['id']]);
} else {
    $courses = $pdo->prepare('SELECT c.id, c.code, c.title, c.test_max AS testMax, c.exam_max AS examMax FROM courses c WHERE c.department_id = (SELECT department_id FROM staff WHERE user_id = ?) AND c.active = 1 ORDER BY c.code');
    $courses->execute([$user['id']]);
    $learners = $pdo->prepare("SELECT DISTINCT s.id, u.full_name AS name, e.course_id AS courseId FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN students s ON s.id = e.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = (SELECT department_id FROM staff WHERE user_id = ?) AND c.active = 1 AND e.status = 'active' ORDER BY c.code, u.full_name");
    $learners->execute([$user['id']]);
}
$scoreRows = $pdo->prepare('SELECT sc.course_id AS courseId, sc.student_id AS studentId, sc.test_score AS test, sc.exam_score AS exam, sc.status FROM scores sc JOIN courses c ON c.id = sc.course_id WHERE c.instructor_id = (SELECT id FROM staff WHERE user_id = ?)');
$scoreRows->execute([$user['id']]);
json(['courses' => $courses->fetchAll(), 'learners' => $learners->fetchAll(), 'scores' => $scoreRows->fetchAll()]);