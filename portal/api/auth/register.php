<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $programs = db()->query("SELECT p.id, p.name, d.name AS department FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.active = 1 ORDER BY d.name, p.name")->fetchAll();
    $cohorts = db()->query("SELECT c.id, c.name, c.code, c.program_id AS programId, c.capacity, COUNT(s.id) AS students FROM cohorts c LEFT JOIN students s ON s.cohort_id = c.id WHERE c.status = 'active' AND c.ends_on >= CURRENT_DATE GROUP BY c.id HAVING COUNT(s.id) < c.capacity ORDER BY c.starts_on, c.name")->fetchAll();
    json(['programs' => $programs, 'cohorts' => $cohorts]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);

$input = requestJson();
$name = trim((string) ($input['name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$confirmation = (string) ($input['passwordConfirmation'] ?? '');
$matric = strtoupper(trim((string) ($input['matric'] ?? '')));
$programId = filter_var($input['programId'] ?? null, FILTER_VALIDATE_INT);
$cohortId = filter_var($input['cohortId'] ?? null, FILTER_VALIDATE_INT) ?: null;
$phone = trim((string) ($input['phone'] ?? ''));
$address = trim((string) ($input['address'] ?? ''));
$nextOfKin = trim((string) ($input['nextOfKin'] ?? ''));
$gender = trim((string) ($input['gender'] ?? ''));
$dateOfBirth = trim((string) ($input['dateOfBirth'] ?? ''));

if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    json(['error' => 'Enter a valid full name and email address.'], 422);
}
if (strlen($password) < 10 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    json(['error' => 'Password must be at least 10 characters and include uppercase, lowercase, and numeric characters.'], 422);
}
if (!hash_equals($password, $confirmation)) json(['error' => 'The password confirmation does not match.'], 422);
if ($matric === '' || mb_strlen($matric) > 40 || !preg_match('/^[A-Z0-9\/-]+$/', $matric) || !$programId) {
    json(['error' => 'Enter a valid matric number and program.'], 422);
}
if (mb_strlen($phone) > 20 || mb_strlen($address) > 500 || mb_strlen($nextOfKin) > 150 || mb_strlen($gender) > 40) {
    json(['error' => 'One or more profile fields exceed the allowed length.'], 422);
}
if ($dateOfBirth !== '') {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateOfBirth || $parsedDate > new DateTimeImmutable('today')) {
        json(['error' => 'Enter a valid date of birth.'], 422);
    }
}

$pdo = db();
$program = $pdo->prepare('SELECT id, department_id FROM programs WHERE id = ? AND active = 1');
$program->execute([$programId]);
$selectedProgram = $program->fetch();
if (!$selectedProgram) json(['error' => 'The selected program is not available for registration.'], 422);
$activeCohorts = $pdo->prepare("SELECT COUNT(*) FROM cohorts c WHERE c.program_id = ? AND c.status = 'active' AND c.ends_on >= CURRENT_DATE");
$activeCohorts->execute([$programId]);
if (!$cohortId && (int) $activeCohorts->fetchColumn() > 0) json(['error' => 'Choose an active cohort for this program.'], 422);
$duplicate = $pdo->prepare('SELECT email FROM users WHERE email = ? UNION SELECT email FROM students WHERE matric_no = ? LIMIT 1');
$duplicate->execute([$email, $matric]);
if ($duplicate->fetchColumn()) json(['error' => 'An account with this email or matric number already exists.'], 409);

$pdo->beginTransaction();
try {
    if ($cohortId) {
        $cohort = $pdo->prepare("SELECT c.id, c.capacity, c.program_id FROM cohorts c WHERE c.id = ? AND c.status = 'active' AND c.ends_on >= CURRENT_DATE FOR UPDATE");
        $cohort->execute([$cohortId]);
        $selectedCohort = $cohort->fetch();
        if (!$selectedCohort || (int) $selectedCohort['program_id'] !== (int) $programId) json(['error' => 'Choose an active cohort for the selected program.'], 422);
        $count = $pdo->prepare('SELECT COUNT(*) FROM students WHERE cohort_id = ?');
        $count->execute([$cohortId]);
        if ((int) $count->fetchColumn() >= (int) $selectedCohort['capacity']) json(['error' => 'This cohort has reached capacity. Choose another cohort.'], 409);
    }
    $createUser = $pdo->prepare("INSERT INTO users (email, password_hash, role, status, full_name) VALUES (?, ?, 'student', 'active', ?)");
    $createUser->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
    $userId = (int) $pdo->lastInsertId();
    $createStudent = $pdo->prepare('INSERT INTO students (user_id, matric_no, program_id, cohort_id, department_id, phone, email, address, next_of_kin, legal_name, date_of_birth, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $createStudent->execute([$userId, $matric, $programId, $cohortId, $selectedProgram['department_id'], $phone, $email, $address, $nextOfKin, $name, $dateOfBirth === '' ? null : $dateOfBirth, $gender]);
    audit($pdo, $userId, 'student_self_registration', 'student', (int) $pdo->lastInsertId(), ['program_id' => $programId]);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($exception->getCode() === '23000') json(['error' => 'An account with this email or matric number already exists.'], 409);
    throw $exception;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

session_regenerate_id(true);
$_SESSION['user'] = ['id' => $userId, 'email' => $email, 'role' => 'student', 'name' => $name];
json(['user' => $_SESSION['user']], 201);
