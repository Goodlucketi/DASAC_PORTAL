<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
$staff = staffId(db(), (int) $user['id']);
$statement = db()->prepare("SELECT ar.id, a.title AS session, c.code, u.full_name AS learner, s.matric_no AS matric, ar.checked_in_at AS checkedInAt, ar.distance_m AS distance, ar.status FROM attendance_records ar JOIN attendance_sessions a ON a.id = ar.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = ar.student_id JOIN users u ON u.id = s.user_id WHERE c.instructor_id = ? AND a.status IN ('open','scheduled') AND NOW() BETWEEN a.starts_at AND a.ends_at ORDER BY ar.checked_in_at DESC");
$statement->execute([$staff]);
json(['checkins' => $statement->fetchAll()]);