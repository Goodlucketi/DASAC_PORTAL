<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['instructor', 'hod', 'director']);
$pdo = db();
$items = [];
$makeTransferItems = static function (array $rows): array {
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'], 'type' => 'transfer', 'learner' => $row['learner'],
        'item' => 'Program transfer: ' . $row['target_program'], 'submitted' => $row['submitted'],
    ], $rows);
};

if ($user['role'] === 'instructor') {
    $query = $pdo->prepare("SELECT tr.id, u.full_name AS learner, p.name AS target_program, DATE(tr.created_at) AS submitted FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = tr.target_program_id JOIN staff st ON st.user_id = ? WHERE tr.status = 'pending_instructor' AND tr.instructor_id = st.id ORDER BY tr.created_at");
    $query->execute([$user['id']]);
    $items = $makeTransferItems($query->fetchAll());
} elseif ($user['role'] === 'hod') {
    $department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
    $department->execute([$user['id']]);
    $departmentId = (int) $department->fetchColumn();
    $scores = $pdo->prepare("SELECT sc.id, u.full_name AS learner, CONCAT(c.code, ' score verification') AS item, DATE(sc.updated_at) AS submitted FROM scores sc JOIN courses c ON c.id = sc.course_id JOIN students s ON s.id = sc.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND sc.status = 'draft' ORDER BY sc.updated_at");
    $scores->execute([$departmentId]);
    foreach ($scores->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'score_verify', 'learner' => $row['learner'], 'item' => $row['item'], 'submitted' => $row['submitted']];
    $transfers = $pdo->prepare("SELECT tr.id, u.full_name AS learner, p.name AS target_program, DATE(tr.created_at) AS submitted FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = tr.target_program_id WHERE (tr.status = 'pending_current_hod' AND tr.current_department_id = ?) OR (tr.status = 'pending_target_hod' AND p.department_id = ?) ORDER BY tr.created_at");
    $transfers->execute([$departmentId, $departmentId]);
    $items = array_merge($items, $makeTransferItems($transfers->fetchAll()));
    $corrections = $pdo->prepare("SELECT ac.id, u.full_name AS learner, CONCAT(c.code, ' attendance correction: ', ac.explanation) AS item, DATE(ac.created_at) AS submitted FROM attendance_corrections ac JOIN attendance_sessions a ON a.id = ac.session_id JOIN courses c ON c.id = a.course_id JOIN students s ON s.id = ac.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND ac.status = 'pending' ORDER BY ac.created_at");
    $corrections->execute([$departmentId]);
    foreach ($corrections->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'attendance_correction', 'learner' => $row['learner'], 'item' => $row['item'], 'submitted' => $row['submitted']];
    $flags = $pdo->prepare("SELECT f.id, u.full_name AS learner, CONCAT(c.code, ' ', f.flag_type, ' score: ', f.note) AS item, DATE(f.created_at) AS submitted FROM score_flags f JOIN courses c ON c.id = f.course_id JOIN students s ON s.id = f.student_id JOIN users u ON u.id = s.user_id WHERE c.department_id = ? AND f.status = 'open' ORDER BY f.created_at");
    $flags->execute([$departmentId]);
    foreach ($flags->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'score_flag', 'learner' => $row['learner'], 'item' => $row['item'], 'submitted' => $row['submitted']];
} elseif ($user['role'] === 'director') {
    $scores = $pdo->prepare("SELECT sc.id, u.full_name AS learner, CONCAT(c.code, ' score publication') AS item, DATE(sc.updated_at) AS submitted FROM scores sc JOIN courses c ON c.id = sc.course_id JOIN students s ON s.id = sc.student_id JOIN users u ON u.id = s.user_id WHERE sc.status = 'verified' ORDER BY sc.updated_at");
    $scores->execute();
    foreach ($scores->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'score_publish', 'learner' => $row['learner'], 'item' => $row['item'], 'submitted' => $row['submitted']];
    $transfers = $pdo->prepare("SELECT tr.id, u.full_name AS learner, p.name AS target_program, DATE(tr.created_at) AS submitted FROM transfer_requests tr JOIN students s ON s.id = tr.student_id JOIN users u ON u.id = s.user_id JOIN programs p ON p.id = tr.target_program_id WHERE tr.status = 'pending_director' ORDER BY tr.created_at");
    $transfers->execute();
    $items = array_merge($items, $makeTransferItems($transfers->fetchAll()));
    $certificates = $pdo->prepare("SELECT c.id, u.full_name AS learner, c.title, CURRENT_DATE AS submitted FROM certificates c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.status = 'pending'");
    $certificates->execute();
    foreach ($certificates->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'certificate', 'learner' => $row['learner'], 'item' => 'Certificate approval: ' . $row['title'], 'submitted' => $row['submitted']];
} else {
    $query = $pdo->prepare("SELECT pcr.id, u.full_name AS learner, CONCAT('Profile correction: ', pcr.field_name) AS item, DATE(pcr.created_at) AS submitted FROM profile_change_requests pcr JOIN students s ON s.id = pcr.student_id JOIN users u ON u.id = s.user_id WHERE pcr.status = 'pending' ORDER BY pcr.created_at");
    $query->execute();
    foreach ($query->fetchAll() as $row) $items[] = ['id' => (int) $row['id'], 'type' => 'profile_correction', 'learner' => $row['learner'], 'item' => $row['item'], 'submitted' => $row['submitted']];
}
json(['items' => $items]);