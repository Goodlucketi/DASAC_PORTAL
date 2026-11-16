<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student']);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);

$studentQuery = $pdo->prepare('SELECT s.id, s.matric_no AS matric, p.name AS program, d.name AS dept, co.name AS cohort, u.status, s.phone, s.email, s.address, s.next_of_kin AS nextOfKin, s.legal_name AS fullName FROM students s JOIN programs p ON p.id = s.program_id JOIN departments d ON d.id = s.department_id JOIN users u ON u.id = s.user_id LEFT JOIN cohorts co ON co.id = s.cohort_id WHERE s.id = ?');
$studentQuery->execute([$sid]);
$student = $studentQuery->fetch();

$attendanceQuery = $pdo->prepare("SELECT COUNT(DISTINCT a.id) AS total, COUNT(DISTINCT ar.id) AS present FROM attendance_sessions a JOIN enrollments e ON e.course_id = a.course_id AND e.student_id = ? AND e.status = 'active' LEFT JOIN attendance_records ar ON ar.session_id = a.id AND ar.student_id = ? WHERE a.starts_at <= NOW()");
$attendanceQuery->execute([$sid, $sid]);
$attendance = $attendanceQuery->fetch();
$recentQuery = $pdo->prepare('SELECT DATE(ar.checked_in_at) AS date, TIME_FORMAT(ar.checked_in_at, "%H:%i") AS time, CONCAT(UPPER(LEFT(ar.status, 1)), SUBSTRING(ar.status, 2)) AS status FROM attendance_records ar WHERE ar.student_id = ? ORDER BY ar.checked_in_at DESC LIMIT 8');
$recentQuery->execute([$sid]);

$scoresQuery = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(AVG(sc.test_score + sc.exam_score), 0) AS average FROM scores sc WHERE sc.student_id = ? AND sc.status = 'published'");
$scoresQuery->execute([$sid]);
$certificateQuery = $pdo->prepare('SELECT status, serial, title FROM certificates WHERE student_id = ? ORDER BY id DESC LIMIT 1');
$certificateQuery->execute([$sid]);
$certificate = $certificateQuery->fetch() ?: ['status' => 'pending', 'serial' => '', 'title' => 'Certificate'];
$announcementQuery = $pdo->prepare('SELECT a.title, a.body AS text, DATE(a.published_at) AS date, a.pinned FROM announcements a WHERE a.active = 1 AND (a.course_id IS NULL OR EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id = a.course_id AND e.student_id = ? AND e.status = "active")) ORDER BY a.pinned DESC, a.published_at DESC');
$announcementQuery->execute([$sid]);

$sessionQuery = $pdo->prepare("SELECT a.id, a.title, DATE(a.starts_at) AS date, TIME_FORMAT(a.starts_at, '%H:%i') AS startTime, TIME_FORMAT(a.ends_at, '%H:%i') AS endTime, g.id AS geofenceId, g.name AS venue, g.latitude AS lat, g.longitude AS lng, g.radius_m AS radius FROM attendance_sessions a JOIN geofences g ON g.id = a.geofence_id JOIN enrollments e ON e.course_id = a.course_id AND e.student_id = ? AND e.status = 'active' WHERE a.status IN ('open','scheduled') AND NOW() BETWEEN a.starts_at AND a.ends_at AND g.active = 1 ORDER BY a.starts_at LIMIT 1");
$sessionQuery->execute([$sid]);
$session = $sessionQuery->fetch() ?: null;
$scoreSummary = $scoresQuery->fetch();

json([
    'student' => $student,
    'policy' => ['minAttendance' => 75, 'passMark' => 50],
    'attendance' => ['present' => (int) $attendance['present'], 'total' => (int) $attendance['total'], 'recent' => $recentQuery->fetchAll()],
    'scores' => ['count' => (int) $scoreSummary['count'], 'average' => (float) $scoreSummary['average']],
    'certificate' => $certificate,
    'announcements' => $announcementQuery->fetchAll(),
    'session' => $session,
]);