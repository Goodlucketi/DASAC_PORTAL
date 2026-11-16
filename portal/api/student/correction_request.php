<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$field = (string) ($_POST['field'] ?? '');
$value = trim((string) ($_POST['value'] ?? ''));
$fields = ['Legal name' => 'legal_name', 'Date of birth' => 'date_of_birth', 'Gender' => 'gender'];
if (!isset($fields[$field]) || $value === '' || mb_strlen($value) > 255) {
    json(['error' => 'Choose a supported field and provide a valid new value.'], 422);
}
if ($fields[$field] === 'date_of_birth') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        json(['error' => 'Enter the date of birth as YYYY-MM-DD.'], 422);
    }
}
$proof = saveUpload('proof', 'profile-corrections', true);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);
$statement = $pdo->prepare('INSERT INTO profile_change_requests (student_id, field_name, requested_value, proof_path) VALUES (?, ?, ?, ?)');
$statement->execute([$sid, $fields[$field], $value, $proof]);
$id = (int) $pdo->lastInsertId();
audit($pdo, (int) $user['id'], 'profile_correction_submit', 'profile_change_request', $id, ['field' => $fields[$field]]);
json(['ok' => true, 'id' => $id], 201);