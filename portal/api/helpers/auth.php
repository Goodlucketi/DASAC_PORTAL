<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    json(['error' => 'The server could not complete the request.'], 500);
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json(['error' => 'Request body must be valid JSON.'], 400);
    }
    return $decoded;
}

function requireRole(array $roles): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['id']) || empty($user['role'])) {
        json(['error' => 'Authentication required.'], 401);
    }
    if (!in_array($user['role'], $roles, true)) {
        json(['error' => 'You are not allowed to perform this action.'], 403);
    }
    return $user;
}

function audit(PDO $pdo, ?int $userId, string $action, string $entityType, int|string|null $entityId = null, array $details = []): void
{
    $statement = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)');
    $statement->execute([
        $userId,
        $action,
        $entityType,
        $entityId === null ? null : (string) $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function studentId(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT id FROM students WHERE user_id = ?');
    $statement->execute([$userId]);
    $id = $statement->fetchColumn();
    if (!$id) {
        json(['error' => 'Student record was not found.'], 404);
    }
    return (int) $id;
}

function requireCourseScope(PDO $pdo, array $user, int $courseId): array
{
    $statement = $pdo->prepare('SELECT c.id, c.department_id, c.instructor_id, c.test_max, c.exam_max, s.id AS staff_id FROM courses c LEFT JOIN staff s ON s.user_id = ? WHERE c.id = ?');
    $statement->execute([$user['id'], $courseId]);
    $course = $statement->fetch();
    if (!$course) {
        json(['error' => 'Course not found.'], 404);
    }
    if ($user['role'] === 'instructor' && (int) $course['instructor_id'] !== (int) ($course['staff_id'] ?? 0)) {
        json(['error' => 'This course is outside your teaching assignment.'], 403);
    }
    if ($user['role'] === 'hod') {
        $scope = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
        $scope->execute([$user['id']]);
        if ((int) $scope->fetchColumn() !== (int) $course['department_id']) {
            json(['error' => 'This course is outside your department.'], 403);
        }
    }
    return $course;
}

function saveUpload(string $field, string $folder, bool $required = false, int $maxBytes = 5242880): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            json(['error' => 'A supporting file is required.'], 422);
        }
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        json(['error' => 'The uploaded file could not be processed.'], 422);
    }
    if ((int) $file['size'] > $maxBytes || (int) $file['size'] < 1) {
        json(['error' => 'File size must be between 1 byte and 5 MB.'], 422);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extensions[$mime])) {
        json(['error' => 'Only PDF, JPEG, and PNG files are accepted.'], 422);
    }

    $base = dirname(__DIR__) . '/storage/uploads/' . $folder;
    if (!is_dir($base) && !mkdir($base, 0750, true) && !is_dir($base)) {
        json(['error' => 'Upload storage is unavailable.'], 500);
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $base . '/' . $filename)) {
        json(['error' => 'The uploaded file could not be saved.'], 500);
    }
    return 'storage/uploads/' . $folder . '/' . $filename;
}

function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLng = deg2rad($lng2 - $lng1);
    $a = sin($deltaLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function staffId(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare('SELECT id FROM staff WHERE user_id = ?');
    $statement->execute([$userId]);
    $id = $statement->fetchColumn();
    if (!$id) {
        json(['error' => 'Staff record was not found.'], 404);
    }
    return (int) $id;
}