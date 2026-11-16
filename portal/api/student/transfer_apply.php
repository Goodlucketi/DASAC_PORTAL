<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/system_settings.php';
$user = requireRole(['student']);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $current = $pdo->prepare('SELECT s.program_id, p.name AS program FROM students s JOIN programs p ON p.id = s.program_id WHERE s.id = ?');
    $current->execute([$sid]);
    $currentProgram = $current->fetch();
    $programs = $pdo->prepare('SELECT id, name FROM programs WHERE active = 1 AND id <> ? ORDER BY name');
    $programs->execute([$currentProgram['program_id']]);
    $requests = $pdo->prepare('SELECT tr.id, p.name AS program, tr.reason, tr.explanation, tr.status, DATE(tr.created_at) AS date FROM transfer_requests tr JOIN programs p ON p.id = tr.target_program_id WHERE tr.student_id = ? ORDER BY tr.created_at DESC');
    $requests->execute([$sid]);
    $rows = $requests->fetchAll();
    $progress = ['pending_instructor' => 0, 'pending_current_hod' => 1, 'pending_target_hod' => 2, 'pending_director' => 3, 'approved' => 4, 'rejected' => 4, 'returned' => 0];
    foreach ($rows as &$row) {
        $row['stage'] = $progress[$row['status']] ?? 0;
        $row['status'] = ucwords(str_replace('_', ' ', $row['status']));
    }
    unset($row);
    json(['currentProgram' => $currentProgram['program'], 'programs' => $programs->fetchAll(), 'reasons' => loadSystemSettings()['transferReasons'], 'transfers' => $rows]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$studentQuery = $pdo->prepare('SELECT program_id, department_id FROM students WHERE id = ?');
$studentQuery->execute([$sid]);
$student = $studentQuery->fetch();
$instructorQuery = $pdo->prepare("SELECT c.instructor_id FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.student_id = ? AND e.status = 'active' AND c.instructor_id IS NOT NULL ORDER BY c.id LIMIT 1");
$instructorQuery->execute([$sid]);
$instructorId = $instructorQuery->fetchColumn();
$programId = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($_POST['reason'] ?? ''));
$explanation = trim((string) ($_POST['explanation'] ?? ''));
$reasonSettings = loadSystemSettings()['transferReasons'];
$configuredReason = null;
foreach ($reasonSettings as $reasonSetting) {
    if (($reasonSetting['label'] ?? '') === $reason) {
        $configuredReason = $reasonSetting;
        break;
    }
}
if (!$programId || !$configuredReason || mb_strlen($explanation) < 20) {
    json(['error' => 'Choose a destination and reason, and provide at least 20 characters of explanation.'], 422);
}
if (!$instructorId) {
    json(['error' => 'No instructor is assigned to your current courses. Contact the department office.'], 409);
}
$targetQuery = $pdo->prepare('SELECT id FROM programs WHERE id = ? AND active = 1 AND id <> ?');
$targetQuery->execute([$programId, $student['program_id']]);
if (!$targetQuery->fetchColumn()) {
    json(['error' => 'The selected destination program is not available.'], 422);
}
$attachment = saveUpload('attachment', 'transfers', !empty($configuredReason['requiresDocument']));
$insert = $pdo->prepare("INSERT INTO transfer_requests (student_id, current_program_id, target_program_id, reason, explanation, attachment_path, current_department_id, instructor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$insert->execute([$sid, $student['program_id'], $programId, $reason, $explanation, $attachment, $student['department_id'], $instructorId]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'transfer_submit', 'transfer_request', $id);
json(['ok' => true, 'id' => $id], 201);