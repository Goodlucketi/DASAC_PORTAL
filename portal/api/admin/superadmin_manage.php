<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/system_settings.php';
$user = requireRole(['superadmin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_starts_with(strtolower($contentType), 'multipart/form-data') ? $_POST : requestJson();
$action = (string) ($input['action'] ?? '');
$pdo = db();

$departmentExists = static function (PDO $pdo, int $id): bool {
    $query = $pdo->prepare('SELECT 1 FROM departments WHERE id = ?');
    $query->execute([$id]);
    return (bool) $query->fetchColumn();
};

if ($action === 'user_create') {
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $role = (string) ($input['role'] ?? '');
    $departmentId = filter_var($input['departmentId'] ?? null, FILTER_VALIDATE_INT);
    $validRoles = ['student', 'instructor', 'hod', 'director', 'superadmin'];
    if ($name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 10 || !in_array($role, $validRoles, true)) {
        json(['error' => 'Enter a valid name, email, role, and initial password of at least 10 characters.'], 422);
    }
    $programId = filter_var($input['programId'] ?? null, FILTER_VALIDATE_INT);
    if ($role === 'student' && !$programId) json(['error' => 'Choose a program for the student account.'], 422);
    if (in_array($role, ['student', 'instructor', 'hod', 'director'], true) && (!$departmentId || !$departmentExists($pdo, (int) $departmentId))) {
        json(['error' => 'Choose a valid department for this account.'], 422);
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO users (email, password_hash, role, status, full_name) VALUES (?, ?, ?, 'active', ?)");
        $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $role, $name]);
        $newUserId = (int) $pdo->lastInsertId();
        if ($role === 'student') {
            $program = $pdo->prepare('SELECT department_id FROM programs WHERE id = ? AND active = 1');
            $program->execute([$programId]);
            $programDepartment = $program->fetchColumn();
            $matric = strtoupper(trim((string) ($input['matric'] ?? '')));
            if (!$programDepartment || (int) $programDepartment !== (int) $departmentId || $matric === '') json(['error' => 'Student matric number and a program in the selected department are required.'], 422);
            $student = $pdo->prepare('INSERT INTO students (user_id, matric_no, program_id, department_id, email, legal_name) VALUES (?, ?, ?, ?, ?, ?)');
            $student->execute([$newUserId, $matric, $programId, $programDepartment, $email, $name]);
        } elseif (in_array($role, ['instructor', 'hod', 'director'], true)) {
            $staff = $pdo->prepare('INSERT INTO staff (user_id, department_id, title) VALUES (?, ?, ?)');
            $staff->execute([$newUserId, $departmentId, trim((string) ($input['title'] ?? ''))]);
        }
        audit($pdo, (int) $user['id'], 'superadmin_user_create', 'user', $newUserId, ['role' => $role, 'department_id' => $departmentId ?: null, 'program_id' => $programId ?: null]);
        $pdo->commit();
        json(['ok' => true, 'id' => $newUserId], 201);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

if ($action === 'user_status') {
    $targetId = filter_var($input['userId'] ?? null, FILTER_VALIDATE_INT);
    $status = (string) ($input['status'] ?? '');
    if (!$targetId || !in_array($status, ['active', 'inactive', 'suspended'], true)) json(['error' => 'A valid account and status are required.'], 422);
    if ((int) $targetId === (int) $user['id'] && $status !== 'active') json(['error' => 'You cannot suspend or remove your own active session account.'], 409);
    $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $target->execute([$targetId]);
    $targetRole = $target->fetchColumn();
    if (!$targetRole) json(['error' => 'Account not found.'], 404);
    if ($targetRole === 'superadmin' && $status !== 'active') {
        $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active'")->fetchColumn();
        if ($activeAdmins <= 1) json(['error' => 'The last active Super Admin cannot be suspended or removed.'], 409);
    }
    $update = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
    $update->execute([$status, $targetId]);
    audit($pdo, (int) $user['id'], 'superadmin_user_status', 'user', $targetId, ['status' => $status]);
    json(['ok' => true]);
}

if ($action === 'user_update') {
    $targetId = filter_var($input['userId'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $role = (string) ($input['role'] ?? '');
    $departmentId = filter_var($input['departmentId'] ?? null, FILTER_VALIDATE_INT);
    $programId = filter_var($input['programId'] ?? null, FILTER_VALIDATE_INT);
    $validRoles = ['student', 'instructor', 'hod', 'director', 'superadmin'];
    if (!$targetId || $name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $validRoles, true)) json(['error' => 'Enter valid account details and role.'], 422);
    $existing = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $existing->execute([$targetId]);
    $currentUser = $existing->fetch();
    if (!$currentUser) json(['error' => 'Account not found.'], 404);
    if (in_array($role, ['student', 'instructor', 'hod', 'director'], true) && (!$departmentId || !$departmentExists($pdo, (int) $departmentId))) json(['error' => 'Choose a valid department for this role.'], 422);
    $studentCheck = $pdo->prepare('SELECT id FROM students WHERE user_id = ?');
    $studentCheck->execute([$targetId]);
    $studentId = $studentCheck->fetchColumn();
    $staffCheck = $pdo->prepare('SELECT id FROM staff WHERE user_id = ?');
    $staffCheck->execute([$targetId]);
    $staffId = $staffCheck->fetchColumn();
    if ($role === 'student' && $staffId) json(['error' => 'This account has staff history and cannot be converted to a student account. Create a separate account to preserve its records.'], 409);
    if (in_array($role, ['instructor', 'hod', 'director'], true) && $studentId) json(['error' => 'This account has student history and cannot be converted to staff. Create a separate account to preserve its records.'], 409);
    if ($role === 'student') {
        $program = $pdo->prepare('SELECT department_id FROM programs WHERE id = ? AND active = 1');
        $program->execute([$programId]);
        $programDepartment = $program->fetchColumn();
        $matric = strtoupper(trim((string) ($input['matric'] ?? '')));
        if (!$programDepartment || (int) $programDepartment !== (int) $departmentId || $matric === '') json(['error' => 'Student matric and a program in the selected department are required.'], 422);
    }
    $status = (string) ($input['status'] ?? $currentUser['status']);
    if (!in_array($status, ['active', 'inactive', 'suspended'], true)) json(['error' => 'Choose a valid account status.'], 422);
    if ((int) $targetId === (int) $user['id'] && ($role !== 'superadmin' || $status !== 'active')) json(['error' => 'You cannot remove your own Super Admin access.'], 409);
    if ($currentUser['role'] === 'superadmin' && ($role !== 'superadmin' || $status !== 'active')) {
        $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND status = 'active'")->fetchColumn();
        if ($activeAdmins <= 1) json(['error' => 'The last active Super Admin cannot be suspended or removed.'], 409);
    }
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, status = ? WHERE id = ?');
        $update->execute([$name, $email, $role, $status, $targetId]);
        if ($role === 'student') {
            $values = [$matric, $programId, $departmentId, $email, $name, $targetId];
            if ($studentId) {
                $saveStudent = $pdo->prepare('UPDATE students SET matric_no = ?, program_id = ?, department_id = ?, email = ?, legal_name = ? WHERE user_id = ?');
                $saveStudent->execute($values);
            } else {
                $saveStudent = $pdo->prepare('INSERT INTO students (matric_no, program_id, department_id, email, legal_name, user_id) VALUES (?, ?, ?, ?, ?, ?)');
                $saveStudent->execute($values);
            }
        } elseif (in_array($role, ['instructor', 'hod', 'director'], true)) {
            $title = trim((string) ($input['title'] ?? ''));
            if ($staffId) {
                $saveStaff = $pdo->prepare('UPDATE staff SET department_id = ?, title = ? WHERE user_id = ?');
                $saveStaff->execute([$departmentId, $title, $targetId]);
            } else {
                $saveStaff = $pdo->prepare('INSERT INTO staff (user_id, department_id, title) VALUES (?, ?, ?)');
                $saveStaff->execute([$targetId, $departmentId, $title]);
            }
        }
        audit($pdo, (int) $user['id'], 'superadmin_user_update', 'user', $targetId, ['role' => $role, 'status' => $status, 'department_id' => $departmentId ?: null, 'program_id' => $programId ?: null]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    json(['ok' => true]);
}

if ($action === 'password_reset') {
    $targetId = filter_var($input['userId'] ?? null, FILTER_VALIDATE_INT);
    $password = (string) ($input['password'] ?? '');
    if (!$targetId || mb_strlen($password) < 10) json(['error' => 'Choose an account and provide a new password of at least 10 characters.'], 422);
    $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
    if (!$update->rowCount()) json(['error' => 'Account not found or password is unchanged.'], 404);
    audit($pdo, (int) $user['id'], 'superadmin_password_reset', 'user', $targetId);
    json(['ok' => true]);
}

if ($action === 'department_save') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string) ($input['name'] ?? ''));
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    if ($name === '' || mb_strlen($name) > 120 || $code === '' || mb_strlen($code) > 20) json(['error' => 'Enter a department name and code.'], 422);
    if ($id) {
        $query = $pdo->prepare('UPDATE departments SET name = ?, code = ? WHERE id = ?');
        $query->execute([$name, $code, $id]);
    } else {
        $query = $pdo->prepare('INSERT INTO departments (name, code) VALUES (?, ?)');
        $query->execute([$name, $code]);
        $id = (int) $pdo->lastInsertId();
    }
    audit($pdo, (int) $user['id'], 'superadmin_department_save', 'department', $id);
    json(['ok' => true, 'id' => $id]);
}

if ($action === 'program_save') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = filter_var($input['departmentId'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string) ($input['name'] ?? ''));
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    if (!$departmentId || !$departmentExists($pdo, (int) $departmentId) || $name === '' || mb_strlen($name) > 150 || $code === '') json(['error' => 'Enter a valid program name, code, and department.'], 422);
    $active = !empty($input['active']) ? 1 : 0;
    if ($id) {
        $query = $pdo->prepare('UPDATE programs SET department_id = ?, name = ?, code = ?, active = ? WHERE id = ?');
        $query->execute([$departmentId, $name, $code, $active, $id]);
    } else {
        $query = $pdo->prepare('INSERT INTO programs (department_id, name, code, active) VALUES (?, ?, ?, ?)');
        $query->execute([$departmentId, $name, $code, $active]);
        $id = (int) $pdo->lastInsertId();
    }
    audit($pdo, (int) $user['id'], 'superadmin_program_save', 'program', $id);
    json(['ok' => true, 'id' => $id]);
}

if ($action === 'course_save') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $departmentId = filter_var($input['departmentId'] ?? null, FILTER_VALIDATE_INT);
    $programId = filter_var($input['programId'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $instructorId = filter_var($input['instructorId'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $title = trim((string) ($input['title'] ?? ''));
    if (!$departmentId || !$departmentExists($pdo, (int) $departmentId) || $code === '' || mb_strlen($code) > 30 || $title === '' || mb_strlen($title) > 180) json(['error' => 'Enter valid course details and department.'], 422);
    if ($programId) {
        $check = $pdo->prepare('SELECT 1 FROM programs WHERE id = ? AND department_id = ?');
        $check->execute([$programId, $departmentId]);
        if (!$check->fetchColumn()) json(['error' => 'The selected program must belong to this department.'], 422);
    }
    if ($instructorId) {
        $check = $pdo->prepare("SELECT 1 FROM staff st JOIN users u ON u.id = st.user_id WHERE st.id = ? AND u.role = 'instructor' AND u.status = 'active'");
        $check->execute([$instructorId]);
        if (!$check->fetchColumn()) json(['error' => 'Choose an active instructor.'], 422);
    }
    $active = !empty($input['active']) ? 1 : 0;
    if ($id) {
        $query = $pdo->prepare('UPDATE courses SET department_id = ?, instructor_id = ?, code = ?, title = ?, active = ? WHERE id = ?');
        $query->execute([$departmentId, $instructorId, $code, $title, $active, $id]);
    } else {
        $query = $pdo->prepare('INSERT INTO courses (department_id, instructor_id, code, title, active) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$departmentId, $instructorId, $code, $title, $active]);
        $id = (int) $pdo->lastInsertId();
    }
    audit($pdo, (int) $user['id'], 'superadmin_course_save', 'course', $id);
    json(['ok' => true, 'id' => $id]);
}

if ($action === 'geofence_save') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string) ($input['name'] ?? ''));
    $latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $radius = filter_var($input['radius'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '' || mb_strlen($name) > 150 || $latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || !$radius || $radius > 50000) json(['error' => 'Enter a valid venue, coordinate, and radius.'], 422);
    $active = !empty($input['active']) ? 1 : 0;
    if ($id) {
        $query = $pdo->prepare('UPDATE geofences SET name = ?, latitude = ?, longitude = ?, radius_m = ?, active = ? WHERE id = ?');
        $query->execute([$name, $latitude, $longitude, $radius, $active, $id]);
    } else {
        $query = $pdo->prepare('INSERT INTO geofences (name, latitude, longitude, radius_m, active) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$name, $latitude, $longitude, $radius, $active]);
        $id = (int) $pdo->lastInsertId();
    }
    audit($pdo, (int) $user['id'], 'superadmin_geofence_save', 'geofence', $id);
    json(['ok' => true, 'id' => $id]);
}

if ($action === 'session_save') {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', (string) ($input['startsAt'] ?? ''));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', (string) ($input['endsAt'] ?? ''));
    $requestedStatus = (string) ($input['status'] ?? '');
    if (!$id || !$start || !$end || $end <= $start || $end->getTimestamp() - $start->getTimestamp() > 86400 * 31 || !in_array($requestedStatus, ['scheduled', 'open', 'closed'], true)) json(['error' => 'Choose a session, valid date range, and status.'], 422);
    $currentStatus = $requestedStatus === 'closed' || $end < new DateTimeImmutable() ? 'closed' : ($start <= new DateTimeImmutable() ? 'open' : 'scheduled');
    $exists = $pdo->prepare('SELECT 1 FROM attendance_sessions WHERE id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) json(['error' => 'Attendance session not found.'], 404);
    $update = $pdo->prepare('UPDATE attendance_sessions SET starts_at = ?, ends_at = ?, status = ? WHERE id = ?');
    $update->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $currentStatus, $id]);
    audit($pdo, (int) $user['id'], 'superadmin_session_window_update', 'attendance_session', $id, ['starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'), 'status' => $currentStatus]);
    json(['ok' => true]);
}

if ($action === 'enrollment_import') {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK || (int) $_FILES['csv']['size'] > 2097152) json(['error' => 'Upload a CSV file no larger than 2 MB.'], 422);
    $handle = fopen($_FILES['csv']['tmp_name'], 'rb');
    if (!$handle) json(['error' => 'The CSV file could not be read.'], 422);
    $header = fgetcsv($handle);
    $header = array_map(static fn($value): string => strtolower(trim((string) $value)), $header ?: []);
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    if ($header !== ['matric_no', 'course_code', 'enrolled_at']) json(['error' => 'CSV columns must be matric_no,course_code,enrolled_at.'], 422);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== 3 || count($rows) >= 500) json(['error' => 'CSV rows must have three columns and contain no more than 500 enrollments.'], 422);
        $rows[] = array_map(static fn($value): string => trim((string) $value), $row);
    }
    fclose($handle);
    if (!$rows) json(['error' => 'The CSV contains no enrollment rows.'], 422);
    $find = $pdo->prepare('SELECT s.id FROM students s WHERE s.matric_no = ?');
    $course = $pdo->prepare('SELECT id FROM courses WHERE code = ? AND active = 1');
    $save = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, enrolled_at, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE enrolled_at = VALUES(enrolled_at), status = 'active'");
    $pdo->beginTransaction();
    try {
        foreach ($rows as [$matric, $code, $date]) {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($matric === '' || $code === '' || !$parsedDate || $parsedDate->format('Y-m-d') !== $date) json(['error' => 'Each enrollment needs a matric number, course code, and valid YYYY-MM-DD date.'], 422);
            $find->execute([$matric]);
            $studentId = $find->fetchColumn();
            $course->execute([$code]);
            $courseId = $course->fetchColumn();
            if (!$studentId || !$courseId) json(['error' => "Unknown student or active course in row {$matric}, {$code}.", 'row' => $matric], 422);
            $save->execute([$studentId, $courseId, $date]);
        }
        audit($pdo, (int) $user['id'], 'superadmin_enrollment_import', 'enrollment', null, ['rows' => count($rows)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    json(['ok' => true, 'imported' => count($rows)]);
}

if ($action === 'settings_save') {
    $current = loadSystemSettings();
    $attendance = is_array($input['attendance'] ?? null) ? $input['attendance'] : [];
    $maxAttempts = filter_var($attendance['maxFailedAttempts'] ?? null, FILTER_VALIDATE_INT);
    $windowMinutes = filter_var($attendance['windowMinutes'] ?? null, FILTER_VALIDATE_INT);
    $reasons = $input['transferReasons'] ?? [];
    if ($maxAttempts === false || $maxAttempts < 1 || $maxAttempts > 30 || $windowMinutes === false || $windowMinutes < 1 || $windowMinutes > 1440 || !is_array($reasons) || count($reasons) < 1 || count($reasons) > 30) json(['error' => 'Attendance limits or transfer reason list are invalid.'], 422);
    $cleanReasons = [];
    foreach ($reasons as $reason) {
        if (!is_array($reason)) json(['error' => 'Each transfer reason must be an object.'], 422);
        $label = trim((string) ($reason['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 100) json(['error' => 'Each transfer reason must have a label up to 100 characters.'], 422);
        $cleanReasons[] = ['label' => $label, 'requiresDocument' => !empty($reason['requiresDocument'])];
    }
    $certificate = is_array($input['certificate'] ?? null) ? $input['certificate'] : [];
    $notifications = is_array($input['notifications'] ?? null) ? $input['notifications'] : [];
    $current['attendance'] = ['maxFailedAttempts' => $maxAttempts, 'windowMinutes' => $windowMinutes];
    $current['transferReasons'] = $cleanReasons;
    $current['certificate'] = [
        'issuer' => mb_substr(trim((string) ($certificate['issuer'] ?? '')), 0, 120),
        'serialPrefix' => mb_substr(strtoupper(trim((string) ($certificate['serialPrefix'] ?? ''))), 0, 30),
        'nextSerial' => max(1, (int) ($certificate['nextSerial'] ?? 1)),
        'wording' => mb_substr(trim((string) ($certificate['wording'] ?? '')), 0, 2000),
        'signatureName' => mb_substr(trim((string) ($certificate['signatureName'] ?? '')), 0, 150),
        'logoUrl' => mb_substr(trim((string) ($certificate['logoUrl'] ?? '')), 0, 500),
        'qrVerifyBaseUrl' => mb_substr(trim((string) ($certificate['qrVerifyBaseUrl'] ?? '')), 0, 500),
    ];
    $channels = is_array($notifications['channels'] ?? null) ? $notifications['channels'] : [];
    $templates = is_array($notifications['templates'] ?? null) ? $notifications['templates'] : [];
    $current['notifications'] = [
        'channels' => ['email' => !empty($channels['email']), 'sms' => !empty($channels['sms']), 'push' => !empty($channels['push'])],
        'templates' => [
            'announcement' => mb_substr(trim((string) ($templates['announcement'] ?? '')), 0, 1000),
            'result' => mb_substr(trim((string) ($templates['result'] ?? '')), 0, 1000),
        ],
    ];
    saveSystemSettings($current);
    audit($pdo, (int) $user['id'], 'superadmin_settings_update', 'system_settings', null);
    json(['ok' => true, 'settings' => $current]);
}

json(['error' => 'Unknown Super Admin action.'], 404);
