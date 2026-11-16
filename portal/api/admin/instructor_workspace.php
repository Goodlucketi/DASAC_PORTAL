<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor']);
$pdo = db();
$staff = staffId($pdo, (int) $user['id']);
$departmentQuery = $pdo->prepare('SELECT department_id FROM staff WHERE id = ?');
$departmentQuery->execute([$staff]);
$departmentId = (int) $departmentQuery->fetchColumn();

$coursesQuery = $pdo->prepare('SELECT id, code, title, test_max AS testMax, exam_max AS examMax FROM courses WHERE instructor_id = ? AND active = 1 ORDER BY code');
$coursesQuery->execute([$staff]);
$courses = $coursesQuery->fetchAll();

$rosterQuery = $pdo->prepare("SELECT c.id AS courseId, c.code, c.title AS course, s.id AS studentId, s.matric_no AS matric, u.full_name AS learner, COALESCE(session_totals.total, 0) AS attendanceTotal, COALESCE(attendance_totals.present, 0) AS attendancePresent, COALESCE(score_totals.average, 0) AS scoreAverage, COALESCE(score_totals.count, 0) AS publishedScores
FROM courses c
JOIN enrollments e ON e.course_id = c.id AND e.status = 'active'
JOIN students s ON s.id = e.student_id
JOIN users u ON u.id = s.user_id
LEFT JOIN (SELECT course_id, COUNT(*) AS total FROM attendance_sessions WHERE starts_at <= NOW() GROUP BY course_id) session_totals ON session_totals.course_id = c.id
LEFT JOIN (SELECT a.course_id, ar.student_id, SUM(ar.status IN ('present','late','excused')) AS present FROM attendance_records ar JOIN attendance_sessions a ON a.id = ar.session_id GROUP BY a.course_id, ar.student_id) attendance_totals ON attendance_totals.course_id = c.id AND attendance_totals.student_id = s.id
LEFT JOIN (SELECT course_id, student_id, AVG(test_score + exam_score) AS average, COUNT(*) AS count FROM scores WHERE status = 'published' GROUP BY course_id, student_id) score_totals ON score_totals.course_id = c.id AND score_totals.student_id = s.id
WHERE c.instructor_id = ? AND c.active = 1 ORDER BY c.code, u.full_name");
$rosterQuery->execute([$staff]);
$roster = $rosterQuery->fetchAll();
$atRisk = [];
foreach ($roster as &$learner) {
    $learner['attendancePercent'] = (int) $learner['attendanceTotal'] > 0
        ? round((int) $learner['attendancePresent'] / (int) $learner['attendanceTotal'] * 100, 1)
        : null;
    $learner['scoreAverage'] = round((float) $learner['scoreAverage'], 1);
    $learner['atRisk'] = ($learner['attendancePercent'] !== null && $learner['attendancePercent'] < 75)
        || ((int) $learner['publishedScores'] > 0 && $learner['scoreAverage'] < 50);
    if ($learner['atRisk']) $atRisk[] = $learner;
}
unset($learner);

$eligibleQuery = $pdo->prepare("SELECT class_students.studentId, class_students.learner, class_students.matric, class_students.courseCount, COALESCE(score_totals.scoreCourses, 0) AS scoreCourses, COALESCE(score_totals.average, 0) AS scoreAverage, COALESCE(attendance_totals.total, 0) AS attendanceTotal, COALESCE(attendance_totals.present, 0) AS attendancePresent
FROM (SELECT s.id AS studentId, u.full_name AS learner, s.matric_no AS matric, COUNT(DISTINCT c.id) AS courseCount FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN students s ON s.id = e.student_id JOIN users u ON u.id = s.user_id WHERE c.instructor_id = ? AND c.active = 1 AND e.status = 'active' GROUP BY s.id, u.full_name, s.matric_no) class_students
LEFT JOIN (SELECT sc.student_id, COUNT(DISTINCT sc.course_id) AS scoreCourses, AVG(sc.test_score + sc.exam_score) AS average FROM scores sc JOIN courses c ON c.id = sc.course_id WHERE c.instructor_id = ? AND sc.status = 'published' GROUP BY sc.student_id) score_totals ON score_totals.student_id = class_students.studentId
LEFT JOIN (SELECT e.student_id, COUNT(DISTINCT a.id) AS total, COUNT(DISTINCT ar.id) AS present FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN attendance_sessions a ON a.course_id = c.id AND a.starts_at <= NOW() LEFT JOIN attendance_records ar ON ar.session_id = a.id AND ar.student_id = e.student_id WHERE c.instructor_id = ? AND e.status = 'active' GROUP BY e.student_id) attendance_totals ON attendance_totals.student_id = class_students.studentId
WHERE NOT EXISTS (SELECT 1 FROM certificates cert WHERE cert.student_id = class_students.studentId AND cert.status IN ('pending','cleared'))
ORDER BY class_students.learner");
$eligibleQuery->execute([$staff, $staff, $staff]);
$eligible = [];
foreach ($eligibleQuery->fetchAll() as $candidate) {
    $candidate['attendancePercent'] = (int) $candidate['attendanceTotal'] > 0
        ? round((int) $candidate['attendancePresent'] / (int) $candidate['attendanceTotal'] * 100, 1)
        : null;
    $candidate['scoreAverage'] = round((float) $candidate['scoreAverage'], 1);
    if ($candidate['attendancePercent'] !== null
        && $candidate['attendancePercent'] >= 75
        && (int) $candidate['scoreCourses'] >= (int) $candidate['courseCount']
        && $candidate['scoreAverage'] >= 50) {
        $eligible[] = $candidate;
    }
}

$sessionQuery = $pdo->prepare("SELECT a.id, a.course_id AS courseId, a.title, DATE_FORMAT(a.starts_at, '%Y-%m-%dT%H:%i') AS startsAt, DATE_FORMAT(a.ends_at, '%Y-%m-%dT%H:%i') AS endsAt, a.status, g.name AS venue FROM attendance_sessions a JOIN courses c ON c.id = a.course_id JOIN geofences g ON g.id = a.geofence_id WHERE c.department_id = ? AND a.ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY a.starts_at DESC LIMIT 50");
$sessionQuery->execute([$departmentId]);
$sessions = $sessionQuery->fetchAll();
$checkinQuery = $pdo->prepare("SELECT ar.id, a.id AS sessionId, a.title AS session, c.code, u.full_name AS learner, s.matric_no AS matric, ar.checked_in_at AS checkedInAt, ar.distance_m AS distance, ar.status FROM attendance_records ar JOIN attendance_sessions a ON a.id = ar.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = ar.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND a.status IN ('open','scheduled') AND NOW() BETWEEN a.starts_at AND a.ends_at ORDER BY ar.checked_in_at DESC");
$checkinQuery->execute([$departmentId]);

$transferQuery = $pdo->prepare("SELECT tr.id, u.full_name AS learner, s.matric_no AS matric, p.name AS targetProgram, tr.reason, tr.explanation, DATE(tr.created_at) AS submitted, (SELECT GROUP_CONCAT(comment ORDER BY created_at SEPARATOR ' | ') FROM transfer_reviews WHERE transfer_id = tr.id AND comment <> '') AS comments FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = tr.target_program_id WHERE tr.instructor_id = ? AND tr.status = 'pending_instructor' ORDER BY tr.created_at");
$transferQuery->execute([$staff]);
$flagQuery = $pdo->prepare("SELECT f.id, c.code, u.full_name AS learner, s.matric_no AS matric, f.flag_type AS type, f.note, DATE(f.created_at) AS created FROM score_flags f JOIN courses c ON c.id = f.course_id JOIN students s ON s.id = f.student_id JOIN users u ON u.id = s.user_id WHERE c.instructor_id = ? AND f.status = 'open' ORDER BY f.created_at DESC");
$flagQuery->execute([$staff]);
$geofenceQuery = $pdo->prepare('SELECT id, name, radius_m AS radius FROM geofences WHERE active = 1 ORDER BY name');
$geofenceQuery->execute();
$announcementQuery = $pdo->prepare('SELECT a.id, c.code, a.title, a.body, DATE(a.published_at) AS published FROM announcements a JOIN courses c ON c.id = a.course_id WHERE a.posted_by = ? ORDER BY a.published_at DESC LIMIT 20');
$announcementQuery->execute([$user['id']]);

json([
    'courses' => $courses,
    'roster' => $roster,
    'atRisk' => $atRisk,
    'sessions' => $sessions,
    'checkins' => $checkinQuery->fetchAll(),
    'transfers' => $transferQuery->fetchAll(),
    'scoreFlags' => $flagQuery->fetchAll(),
    'certificateEligible' => $eligible,
    'geofences' => $geofenceQuery->fetchAll(),
    'announcements' => $announcementQuery->fetchAll(),
]);