<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/system_settings.php';
requireRole(['superadmin']);
$pdo = db();

$users = $pdo->query("SELECT u.id, u.full_name AS name, u.email, u.role, u.status, COALESCE(st.department_id, s.department_id) AS departmentId, s.program_id AS programId, s.matric_no AS matric, st.title FROM users u LEFT JOIN staff st ON st.user_id = u.id LEFT JOIN students s ON s.user_id = u.id ORDER BY u.created_at DESC LIMIT 200")->fetchAll();
$departments = $pdo->query('SELECT id, name, code FROM departments ORDER BY name')->fetchAll();
$programs = $pdo->query('SELECT p.id, p.name, p.code, p.department_id AS departmentId, p.active FROM programs p ORDER BY p.name')->fetchAll();
$courses = $pdo->query('SELECT c.id, c.code, c.title, c.department_id AS departmentId, c.instructor_id AS instructorId, c.active FROM courses c ORDER BY c.code')->fetchAll();
$instructors = $pdo->query("SELECT st.id, u.full_name AS name FROM staff st JOIN users u ON u.id = st.user_id WHERE u.role = 'instructor' AND u.status = 'active' ORDER BY u.full_name")->fetchAll();
$enrollments = $pdo->query("SELECT e.id, s.matric_no AS matric, u.full_name AS learner, c.code AS course, e.enrolled_at AS enrolledAt, e.status FROM enrollments e JOIN students s ON s.id = e.student_id JOIN users u ON u.id = s.user_id JOIN courses c ON c.id = e.course_id ORDER BY e.id DESC LIMIT 100")->fetchAll();
$geofences = $pdo->query('SELECT g.id, g.name, g.latitude, g.longitude, g.radius_m AS radius, g.active FROM geofences g ORDER BY g.name')->fetchAll();
$sessions = $pdo->query("SELECT a.id, a.title, c.code AS course, DATE_FORMAT(a.starts_at, '%Y-%m-%dT%H:%i') AS startsAt, DATE_FORMAT(a.ends_at, '%Y-%m-%dT%H:%i') AS endsAt, a.status, g.name AS venue FROM attendance_sessions a JOIN courses c ON c.id = a.course_id JOIN geofences g ON g.id = a.geofence_id ORDER BY a.starts_at DESC LIMIT 30")->fetchAll();
$audit = $pdo->query("SELECT al.action, al.entity_type AS entityType, al.entity_id AS entityId, al.created_at AS createdAt, COALESCE(u.full_name, 'System') AS actor FROM audit_log al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 100")->fetchAll();
$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn(),
    'staff' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('instructor','hod','director') AND status = 'active'")->fetchColumn(),
    'courses' => (int) $pdo->query('SELECT COUNT(*) FROM courses WHERE active = 1')->fetchColumn(),
    'enrollments' => (int) $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'active'")->fetchColumn(),
];
$integrity = [
    'studentsWithoutProfile' => (int) $pdo->query("SELECT COUNT(*) FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.role = 'student' AND s.id IS NULL")->fetchColumn(),
    'staffWithoutProfile' => (int) $pdo->query("SELECT COUNT(*) FROM users u LEFT JOIN staff st ON st.user_id = u.id WHERE u.role IN ('instructor','hod','director') AND st.id IS NULL")->fetchColumn(),
    'expiredSessionsOpen' => (int) $pdo->query("SELECT COUNT(*) FROM attendance_sessions WHERE status <> 'closed' AND ends_at < NOW()")->fetchColumn(),
];
json(['summary' => $counts, 'users' => $users, 'departments' => $departments, 'programs' => $programs, 'courses' => $courses, 'instructors' => $instructors, 'enrollments' => $enrollments, 'geofences' => $geofences, 'sessions' => $sessions, 'audit' => $audit, 'integrity' => $integrity, 'settings' => loadSystemSettings()]);