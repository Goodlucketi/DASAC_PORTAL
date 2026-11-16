<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student']);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $statement = $pdo->prepare('SELECT s.matric_no AS matric, p.name AS program, d.name AS dept, co.name AS cohort, u.status, s.phone, s.email, s.address, s.next_of_kin AS nextOfKin, s.legal_name AS fullName FROM students s JOIN programs p ON p.id = s.program_id JOIN departments d ON d.id = s.department_id JOIN users u ON u.id = s.user_id LEFT JOIN cohorts co ON co.id = s.cohort_id WHERE s.id = ?');
    $statement->execute([$sid]);
    json(['profile' => $statement->fetch()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$phone = trim((string) ($input['phone'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$address = trim((string) ($input['address'] ?? ''));
$nextOfKin = trim((string) ($input['nextOfKin'] ?? ''));
if (!preg_match('/^[0-9]{11}$/', $phone) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $address === '' || $nextOfKin === '') {
    json(['error' => 'Enter a valid 11-digit phone number, email, address, and next of kin.'], 422);
}
$pdo->beginTransaction();
try {
    $collision = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $collision->execute([$email, $user['id']]);
    if ($collision->fetchColumn()) {
        $pdo->rollBack();
        json(['error' => 'That email address is already in use.'], 409);
    }
    $updateStudent = $pdo->prepare('UPDATE students SET phone = ?, email = ?, address = ?, next_of_kin = ? WHERE id = ?');
    $updateStudent->execute([$phone, $email, $address, $nextOfKin, $sid]);
    $updateUser = $pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
    $updateUser->execute([$email, $user['id']]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
$_SESSION['user']['email'] = $email;
audit($pdo, (int) $user['id'], 'profile_update', 'student', $sid);
json(['ok' => true]);