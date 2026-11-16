<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['hod']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$flagId = filter_var($input['flag_id'] ?? null, FILTER_VALIDATE_INT);
if (!$flagId) json(['error' => 'A valid score flag is required.'], 422);
$pdo = db();
$department = $pdo->prepare('SELECT department_id FROM staff WHERE user_id = ?');
$department->execute([$user['id']]);
$departmentId = (int) $department->fetchColumn();
$update = $pdo->prepare("UPDATE score_flags f JOIN courses c ON c.id = f.course_id SET f.status = 'resolved' WHERE f.id = ? AND f.status = 'open' AND c.department_id = ?");
$update->execute([$flagId, $departmentId]);
if (!$update->rowCount()) json(['error' => 'Open score flag was not found in your department.'], 404);
audit($pdo, (int) $user['id'], 'score_flag_resolve', 'score_flag', $flagId);
json(['ok' => true]);