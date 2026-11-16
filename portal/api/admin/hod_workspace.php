<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
$pdo = db();
$staff = staffId($pdo, (int) $user['id']);
$departmentQuery = $pdo->prepare('SELECT d.id, d.name FROM staff s JOIN departments d ON d.id = s.department_id WHERE s.id = ?');
$departmentQuery->execute([$staff]);
$department = $departmentQuery->fetch();
if (!$department) json(['error' => 'Department record was not found.'], 404);
$departmentId = (int) $department['id'];

$programQuery = $pdo->prepare("SELECT p.id, p.name, p.code,
  COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.student_id END) AS activeStudents,
  COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.student_id END) AS completedStudents,
  COUNT(DISTINCT CASE WHEN e.status = 'withdrawn' THEN e.student_id END) AS dropouts,
  COALESCE(score_stats.scoreCount, 0) AS scoreCount,
  COALESCE(score_stats.passRate, 0) AS passRate,
  COALESCE(attendance_stats.attendanceRate, 0) AS attendanceRate
FROM programs p
LEFT JOIN students s ON s.program_id = p.id
LEFT JOIN enrollments e ON e.student_id = s.id
LEFT JOIN (
  SELECT s2.program_id, COUNT(*) AS scoreCount, AVG(CASE WHEN sc.test_score + sc.exam_score >= 50 THEN 1 ELSE 0 END) * 100 AS passRate
  FROM scores sc
  JOIN students s2 ON s2.id = sc.student_id
  JOIN courses c2 ON c2.id = sc.course_id
  WHERE c2.department_id = ?
  GROUP BY s2.program_id
) score_stats ON score_stats.program_id = p.id
LEFT JOIN (
  SELECT s3.program_id,
         COUNT(DISTINCT a.id) AS totalSessions,
         COUNT(DISTINCT ar.id) AS attendedSessions,
         (COUNT(DISTINCT ar.id) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100 AS attendanceRate
  FROM students s3
  JOIN enrollments e3 ON e3.student_id = s3.id AND e3.status = 'active'
  JOIN courses c3 ON c3.id = e3.course_id
  LEFT JOIN attendance_sessions a ON a.course_id = c3.id AND a.starts_at <= NOW()
  LEFT JOIN attendance_records ar ON ar.session_id = a.id AND ar.student_id = s3.id
  WHERE s3.department_id = ?
  GROUP BY s3.program_id
) attendance_stats ON attendance_stats.program_id = p.id
WHERE p.department_id = ? AND p.active = 1
GROUP BY p.id, p.name, p.code, score_stats.scoreCount, score_stats.passRate, attendance_stats.attendanceRate
ORDER BY p.name");
$programQuery->execute([$departmentId, $departmentId, $departmentId]);
$programs = $programQuery->fetchAll();
foreach ($programs as &$program) {
    $program['activeStudents'] = (int) $program['activeStudents'];
    $program['completedStudents'] = (int) $program['completedStudents'];
    $program['dropouts'] = (int) $program['dropouts'];
    $program['scoreCount'] = (int) $program['scoreCount'];
    $program['passRate'] = round((float) $program['passRate'], 1);
    $program['attendanceRate'] = round((float) $program['attendanceRate'], 1);
}
unset($program);

$courseQuery = $pdo->prepare('SELECT c.id, c.code, c.title, c.test_max AS testMax, c.exam_max AS examMax, c.active, d.name AS department, st.id AS instructorId, u.full_name AS instructor FROM courses c JOIN departments d ON d.id = c.department_id LEFT JOIN staff st ON st.id = c.instructor_id LEFT JOIN users u ON u.id = st.user_id WHERE c.department_id = ? ORDER BY c.code');
$courseQuery->execute([$departmentId]);
$instructorsQuery = $pdo->prepare("SELECT st.id, u.full_name AS name, st.title FROM staff st JOIN users u ON u.id = st.user_id WHERE st.department_id = ? AND u.role = 'instructor' AND u.status = 'active' ORDER BY u.full_name");
$instructorsQuery->execute([$departmentId]);
$venueQuery = $pdo->prepare('SELECT id, name, latitude AS lat, longitude AS lng, radius_m AS radius, active FROM geofences WHERE active = 1 ORDER BY name');
$venueQuery->execute();

$scoresQuery = $pdo->prepare("SELECT sc.id, c.code, u.full_name AS learner, s.matric_no AS matric, sc.test_score AS test, sc.exam_score AS exam, DATE(sc.updated_at) AS submitted FROM scores sc JOIN courses c ON c.id = sc.course_id JOIN students s ON s.id = sc.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND sc.status = 'draft' ORDER BY sc.updated_at");
$scoresQuery->execute([$departmentId]);
$amendmentsQuery = $pdo->prepare("SELECT sa.id, sc.id AS scoreId, c.code, u.full_name AS learner, s.matric_no AS matric, sa.test_score AS test, sa.exam_score AS exam, sa.request_reason AS reason, sa.created_at AS submitted FROM score_amendments sa JOIN scores sc ON sc.id = sa.score_id JOIN courses c ON c.id = sc.course_id JOIN students s ON s.id = sc.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND sa.status = 'pending' ORDER BY sa.created_at");
$amendmentsQuery->execute([$departmentId]);
$correctionsQuery = $pdo->prepare("SELECT ac.id, a.title AS session, c.code, u.full_name AS learner, s.matric_no AS matric, ac.requested_status AS requestedStatus, ac.explanation AS reason, ac.created_at AS submitted FROM attendance_corrections ac JOIN attendance_sessions a ON a.id = ac.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = ac.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND ac.status = 'pending' ORDER BY ac.created_at");
$correctionsQuery->execute([$departmentId]);
$attemptsQuery = $pdo->prepare("SELECT aa.id, c.code, a.title AS session, u.full_name AS learner, s.matric_no AS matric, aa.distance_m AS distance, aa.flag_reason AS reason, aa.created_at AS attemptedAt FROM attendance_attempts aa JOIN attendance_sessions a ON a.id = aa.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = aa.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND aa.reviewed_at IS NULL AND aa.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY aa.created_at DESC LIMIT 100");
$attemptsQuery->execute([$departmentId]);

$outgoingTransfers = $pdo->prepare("SELECT tr.id, u.full_name AS learner, s.matric_no AS matric, cp.name AS currentProgram, tp.name AS targetProgram, tr.reason, tr.explanation, DATE(tr.created_at) AS submitted, 'outgoing' AS direction FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs cp ON cp.id = tr.current_program_id JOIN programs tp ON tp.id = tr.target_program_id WHERE tr.status = 'pending_current_hod' AND tr.current_department_id = ? ORDER BY tr.created_at");
$outgoingTransfers->execute([$departmentId]);
$incomingTransfers = $pdo->prepare("SELECT tr.id, tr.student_id AS studentId, u.full_name AS learner, s.matric_no AS matric, cp.name AS currentProgram, tp.name AS targetProgram, tr.reason, tr.explanation, DATE(tr.created_at) AS submitted, (SELECT COUNT(DISTINCT active_s.id) FROM students active_s JOIN enrollments active_e ON active_e.student_id = active_s.id WHERE active_s.program_id = tp.id AND active_e.status = 'active') AS targetEnrollment, NOT EXISTS (SELECT 1 FROM program_prerequisites pp WHERE pp.program_id = tp.id AND NOT EXISTS (SELECT 1 FROM scores prereq_score WHERE prereq_score.student_id = tr.student_id AND prereq_score.course_id = pp.course_id AND prereq_score.status = 'published' AND prereq_score.test_score + prereq_score.exam_score >= 50)) AS prerequisitesMet, 'incoming' AS direction FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs cp ON cp.id = tr.current_program_id JOIN programs tp ON tp.id = tr.target_program_id WHERE tr.status = 'pending_target_hod' AND tp.department_id = ? ORDER BY tr.created_at");
$incomingTransfers->execute([$departmentId]);

$certificateQuery = $pdo->prepare("SELECT s.id AS studentId, u.full_name AS learner, s.matric_no AS matric, p.name AS program, p.id AS programId, COALESCE(attendance_stats.rate, 0) AS attendanceRate, COALESCE(score_stats.average, 0) AS scoreAverage, COALESCE(score_stats.courseCount, 0) AS scoredCourses, course_stats.courseCount AS requiredCourses FROM students s JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = s.program_id LEFT JOIN (SELECT c2.department_id, COUNT(*) AS courseCount FROM courses c2 WHERE c2.active = 1 GROUP BY c2.department_id) course_stats ON course_stats.department_id = p.department_id LEFT JOIN (SELECT e.student_id, COUNT(DISTINCT a.id) AS sessions, COUNT(DISTINCT ar.id) AS attended, COUNT(DISTINCT ar.id) / NULLIF(COUNT(DISTINCT a.id), 0) * 100 AS rate FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN attendance_sessions a ON a.course_id = c.id AND a.starts_at <= NOW() LEFT JOIN attendance_records ar ON ar.session_id = a.id AND ar.student_id = e.student_id WHERE e.status = 'active' GROUP BY e.student_id) attendance_stats ON attendance_stats.student_id = s.id LEFT JOIN (SELECT sc.student_id, COUNT(DISTINCT sc.course_id) AS courseCount, AVG(sc.test_score + sc.exam_score) AS average FROM scores sc JOIN courses c ON c.id = sc.course_id WHERE c.active = 1 AND c.department_id = ? AND sc.status = 'published' GROUP BY sc.student_id) score_stats ON score_stats.student_id = s.id WHERE p.department_id = ? AND NOT EXISTS (SELECT 1 FROM certificates cert WHERE cert.student_id = s.id AND cert.status IN ('pending','recommended','cleared')) ORDER BY p.name, u.full_name");
$certificateQuery->execute([$departmentId, $departmentId]);
$eligible = [];
foreach ($certificateQuery->fetchAll() as $candidate) {
    if ((int) $candidate['requiredCourses'] === 0 || (int) $candidate['scoredCourses'] < (int) $candidate['requiredCourses']) continue;
    if ((float) $candidate['attendanceRate'] < 75 || (float) $candidate['scoreAverage'] < 50) continue;
    $eligible[] = $candidate;
}

$announcementQuery = $pdo->prepare("SELECT a.id, a.audience, a.title, a.body, DATE(a.published_at) AS published, p.name AS program FROM announcements a LEFT JOIN programs p ON p.id = a.program_id WHERE a.posted_by = ? AND a.audience IN ('department','program') ORDER BY a.published_at DESC LIMIT 30");
$announcementQuery->execute([$user['id']]);
$sessionQuery = $pdo->prepare("SELECT a.id, a.title, c.code, d.name AS department, a.status, a.starts_at AS startsAt, a.ends_at AS endsAt, g.name AS venue, u.full_name AS instructor FROM attendance_sessions a JOIN courses c ON c.id = a.course_id JOIN departments d ON d.id = c.department_id JOIN geofences g ON g.id = a.geofence_id LEFT JOIN staff st ON st.id = a.assigned_to LEFT JOIN users u ON u.id = st.user_id WHERE c.department_id = ? ORDER BY a.starts_at DESC LIMIT 100");
$sessionQuery->execute([$departmentId]);

json([
    'department' => $department,
    'programs' => $programs,
    'courses' => $courseQuery->fetchAll(),
    'instructors' => $instructorsQuery->fetchAll(),
    'venues' => $venueQuery->fetchAll(),
    'scores' => $scoresQuery->fetchAll(),
    'amendments' => $amendmentsQuery->fetchAll(),
    'attendanceCorrections' => $correctionsQuery->fetchAll(),
    'attendanceFlags' => $attemptsQuery->fetchAll(),
    'outgoingTransfers' => $outgoingTransfers->fetchAll(),
    'incomingTransfers' => $incomingTransfers->fetchAll(),
    'certificateEligible' => $eligible,
    'announcements' => $announcementQuery->fetchAll(),
    'sessions' => $sessionQuery->fetchAll(),
]);