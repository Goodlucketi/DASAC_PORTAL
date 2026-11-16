<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/policies.php';

$user = requireRole(['director', 'superadmin']);
$pdo = db();
$policy = loadPolicy();

$expiredCohorts = $pdo->query("SELECT id FROM cohorts WHERE status = 'active' AND ends_on < CURRENT_DATE")->fetchAll(PDO::FETCH_COLUMN);
if ($expiredCohorts) {
  $closeExpired = $pdo->prepare("UPDATE cohorts SET status = 'closed' WHERE id = ? AND status = 'active'");
  foreach ($expiredCohorts as $cohortId) {
    $closeExpired->execute([$cohortId]);
    if ($closeExpired->rowCount()) audit($pdo, null, 'cohort_auto_end', 'cohort', (int) $cohortId, ['reason' => 'six_month_duration_elapsed']);
  }
}

$summaryQuery = $pdo->query("SELECT
  (SELECT COUNT(*) FROM programs WHERE active = 1) AS programCount,
  (SELECT COUNT(DISTINCT s.id) FROM students s JOIN enrollments e ON e.student_id = s.id WHERE e.status = 'active') AS activeLearners,
  (SELECT COUNT(*) FROM scores WHERE status = 'published') AS publishedScores,
  (SELECT COUNT(*) FROM scores WHERE status = 'verified') AS pendingScores,
  (SELECT COUNT(*) FROM transfer_requests WHERE status IN ('pending_instructor', 'pending_current_hod', 'pending_target_hod', 'pending_director')) AS pendingTransfers,
  (SELECT COUNT(*) FROM certificates WHERE status = 'pending') AS pendingCertificates");
$summary = $summaryQuery->fetch() ?: [];

$departmentsQuery = $pdo->prepare("SELECT d.name, COUNT(DISTINCT s.id) AS learners, COUNT(DISTINCT CASE WHEN e.status = 'active' THEN e.id END) AS activeEnrollments, COUNT(DISTINCT CASE WHEN sc.status = 'published' THEN sc.id END) AS publishedScores,
  COALESCE((SELECT ROUND(100 * AVG(ar.status IN ('present','late')), 1) FROM courses c2 JOIN attendance_sessions a2 ON a2.course_id = c2.id JOIN attendance_records ar ON ar.session_id = a2.id WHERE c2.department_id = d.id), 0) AS attendanceRate,
  COALESCE((SELECT ROUND(100 * AVG((sc2.test_score + sc2.exam_score) >= ?), 1) FROM courses c3 JOIN scores sc2 ON sc2.course_id = c3.id WHERE c3.department_id = d.id AND sc2.status = 'published'), 0) AS passRate,
  (SELECT COUNT(*) FROM certificates cert JOIN students s2 ON s2.id = cert.student_id WHERE s2.department_id = d.id AND cert.status = 'cleared') AS certificatesIssued,
  (SELECT COUNT(DISTINCT tr.id) FROM transfer_requests tr LEFT JOIN programs p2 ON p2.id = tr.target_program_id WHERE tr.current_department_id = d.id OR p2.department_id = d.id) AS transfers
  FROM departments d LEFT JOIN students s ON s.department_id = d.id LEFT JOIN enrollments e ON e.student_id = s.id LEFT JOIN scores sc ON sc.student_id = s.id AND sc.status = 'published' GROUP BY d.id, d.name ORDER BY d.name");
$departmentsQuery->execute([(float) ($policy['passMark'] ?? 50)]);

$transfersQuery = $pdo->query("SELECT tr.id, u.full_name AS learner, p.name AS targetProgram, tr.status, tr.reason, DATE(tr.created_at) AS submitted FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = tr.target_program_id WHERE tr.status IN ('pending_instructor', 'pending_current_hod', 'pending_target_hod', 'pending_director') ORDER BY tr.created_at DESC LIMIT 12");

$certificatesQuery = $pdo->query("SELECT cert.id, u.full_name AS learner, cert.title, cert.serial, cert.status, DATE(cert.approved_at) AS submitted FROM certificates cert JOIN students s ON s.id = cert.student_id JOIN users u ON u.id = s.user_id WHERE cert.status IN ('pending','cleared') ORDER BY (cert.status = 'pending') DESC, cert.id DESC LIMIT 100");

$publishedScoresQuery = $pdo->query("SELECT sc.id, u.full_name AS learner, c.code, c.title AS course, (sc.test_score + sc.exam_score) AS total, DATE(sc.updated_at) AS published FROM scores sc JOIN courses c ON c.id = sc.course_id JOIN students s ON s.id = sc.student_id JOIN users u ON u.id = s.user_id WHERE sc.status = 'published' ORDER BY sc.updated_at DESC LIMIT 25");

$announcementsQuery = $pdo->query("SELECT a.id, a.title, a.body, a.pinned, DATE(a.published_at) AS published, a.audience FROM announcements a WHERE a.active = 1 AND a.audience = 'all' ORDER BY a.pinned DESC, a.published_at DESC LIMIT 12");

$programsQuery = $pdo->query("SELECT id, name, code FROM programs WHERE active = 1 ORDER BY name");
$cohortsQuery = $pdo->query("SELECT co.id, co.name, co.code, co.program_id AS programId, p.name AS program, co.capacity, co.starts_on AS startsOn, co.ends_on AS endsOn, co.status, COUNT(DISTINCT s.id) AS students FROM cohorts co JOIN programs p ON p.id = co.program_id LEFT JOIN students s ON s.cohort_id = co.id GROUP BY co.id, p.name ORDER BY co.created_at DESC");

$auditQuery = $pdo->query("SELECT al.id, al.action, al.entity_type, al.entity_id, DATE_FORMAT(al.created_at, '%Y-%m-%d %H:%i') AS createdAt, COALESCE(u.full_name, 'System') AS actor FROM audit_log al LEFT JOIN users u ON u.id = al.user_id ORDER BY al.created_at DESC LIMIT 18");

$json = [
    'summary' => [
        'programCount' => (int) ($summary['programCount'] ?? 0),
        'activeLearners' => (int) ($summary['activeLearners'] ?? 0),
        'publishedScores' => (int) ($summary['publishedScores'] ?? 0),
        'pendingScores' => (int) ($summary['pendingScores'] ?? 0),
        'pendingTransfers' => (int) ($summary['pendingTransfers'] ?? 0),
        'pendingCertificates' => (int) ($summary['pendingCertificates'] ?? 0),
    ],
    'departments' => $departmentsQuery->fetchAll(),
    'transfers' => $transfersQuery->fetchAll(),
    'certificates' => $certificatesQuery->fetchAll(),
    'publishedScores' => $publishedScoresQuery->fetchAll(),
    'announcements' => $announcementsQuery->fetchAll(),
    'programs' => $programsQuery->fetchAll(),
    'cohorts' => $cohortsQuery->fetchAll(),
    'audit' => $auditQuery->fetchAll(),
    'policy' => $policy,
];

json($json);
