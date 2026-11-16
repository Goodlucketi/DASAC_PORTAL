<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/policies.php';

$user = requireRole(['director', 'superadmin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}

$input = requestJson();
$minAttendance = filter_var($input['minAttendance'] ?? null, FILTER_VALIDATE_FLOAT);
$passMark = filter_var($input['passMark'] ?? null, FILTER_VALIDATE_FLOAT);

if ($minAttendance === false || $passMark === false) {
    json(['error' => 'A valid minimum attendance and pass mark are required.'], 422);
}

if ($minAttendance < 0 || $minAttendance > 100 || $passMark < 0 || $passMark > 100) {
    json(['error' => 'Attendance and pass markers must stay between 0 and 100.'], 422);
}

$policy = loadPolicy();
$policy['minAttendance'] = (float) round($minAttendance, 1);
$policy['passMark'] = (float) round($passMark, 1);

try {
    savePolicy($policy);
} catch (Throwable $exception) {
    json(['error' => 'The policy file could not be saved.'], 500);
}

audit(db(), (int) $user['id'], 'policy_update', 'policy', 'portal_settings', $policy);
json(['ok' => true, 'policy' => $policy]);
