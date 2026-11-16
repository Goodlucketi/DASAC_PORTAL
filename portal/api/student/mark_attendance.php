<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/system_settings.php';
$user = requireRole(['student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$latitude = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$accuracy = filter_var($input['accuracy'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $accuracy === false || $accuracy < 0 || $accuracy > 1000) {
    json(['error' => 'A valid GPS location and accuracy measurement are required.'], 422);
}

$pdo = db();
$sid = studentId($pdo, (int) $user['id']);
$statement = $pdo->prepare("SELECT a.id, a.starts_at, g.latitude, g.longitude, g.radius_m, g.name FROM attendance_sessions a JOIN geofences g ON g.id = a.geofence_id JOIN enrollments e ON e.course_id = a.course_id AND e.student_id = ? AND e.status = 'active' WHERE a.status IN ('open','scheduled') AND NOW() BETWEEN a.starts_at AND a.ends_at AND g.active = 1 ORDER BY a.starts_at LIMIT 1");
$statement->execute([$sid]);
$session = $statement->fetch();
if (!$session) {
    json(['error' => 'There is no open attendance session for your enrolled courses.'], 409);
}
$distance = haversineMeters((float) $session['latitude'], (float) $session['longitude'], (float) $latitude, (float) $longitude);
$radius = (int) $session['radius_m'];
if ($distance + $accuracy > $radius) {
    $rules = loadSystemSettings()['attendance'];
    $maxAttempts = max(1, min(30, (int) ($rules['maxFailedAttempts'] ?? 5)));
    $windowMinutes = max(1, min(1440, (int) ($rules['windowMinutes'] ?? 30)));
    $cutoff = (new DateTimeImmutable())->modify('-' . $windowMinutes . ' minutes')->format('Y-m-d H:i:s');
    $failureCount = $pdo->prepare('SELECT COUNT(*) FROM attendance_attempts WHERE session_id = ? AND student_id = ? AND created_at >= ?');
    $failureCount->execute([$session['id'], $sid, $cutoff]);
    $recentFailures = (int) $failureCount->fetchColumn();
    if ($recentFailures >= $maxAttempts) {
        json(['error' => 'Too many unsuccessful check-in attempts. Try again after the configured security window.'], 429);
    }
    $outsideRadius = $distance > $radius;
    $flag = $outsideRadius
        ? ($recentFailures >= 2 ? 'repeated_failure' : 'outside_geofence')
        : 'suspicious_location';
    $attempt = $pdo->prepare('INSERT INTO attendance_attempts (session_id, student_id, latitude, longitude, distance_m, flag_reason) VALUES (?, ?, ?, ?, ?, ?)');
    $attempt->execute([$session['id'], $sid, $latitude, $longitude, $distance, $flag]);
    audit($pdo, (int) $user['id'], 'attendance_checkin_rejected', 'attendance_attempt', (int) $pdo->lastInsertId(), ['distance_m' => round($distance, 2), 'accuracy_m' => round($accuracy, 2), 'reason' => $flag]);
    if (!$outsideRadius) json(['error' => 'GPS accuracy is insufficient to confirm your location is within the geofence.', 'reason' => 'inaccurate_location', 'distance' => round($distance), 'accuracy' => round($accuracy), 'radius' => $radius], 422);
    json(['error' => 'You are outside the attendance geofence.', 'distance' => round($distance), 'radius' => $radius], 422);
}

try {
    $insert = $pdo->prepare("INSERT INTO attendance_records (session_id, student_id, checked_in_at, distance_m, status) VALUES (?, ?, NOW(), ?, 'present')");
    $insert->execute([(int) $session['id'], $sid, $distance]);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        $attempt = $pdo->prepare("INSERT INTO attendance_attempts (session_id, student_id, latitude, longitude, distance_m, flag_reason) VALUES (?, ?, ?, ?, ?, 'duplicate_checkin')");
        $attempt->execute([$session['id'], $sid, $latitude, $longitude, $distance]);
        json(['error' => 'You have already checked in to this session.'], 409);
    }
    throw $exception;
}
$recordId = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'attendance_checkin', 'attendance_record', $recordId, ['session_id' => (int) $session['id'], 'distance_m' => round($distance, 2), 'accuracy_m' => round($accuracy, 2)]);
json(['ok' => true, 'distance' => round($distance), 'venue' => $session['name']]);