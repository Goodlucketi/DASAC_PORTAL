<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['director']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json(['error' => 'Method not allowed.'], 405);
$input = requestJson();
$action = (string) ($input['action'] ?? '');
$pdo = db();

if ($action === 'cohort_create') {
    $programId = filter_var($input['programId'] ?? null, FILTER_VALIDATE_INT);
    $name = trim((string) ($input['name'] ?? ''));
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $capacity = filter_var($input['capacity'] ?? null, FILTER_VALIDATE_INT);
    if (!$programId || $name === '' || mb_strlen($name) > 150 || $code === '' || mb_strlen($code) > 40 || !$capacity || $capacity > 100000) {
        json(['error' => 'Enter a cohort name, unique code, program, and valid capacity.'], 422);
    }
    $program = $pdo->prepare('SELECT id FROM programs WHERE id = ? AND active = 1');
    $program->execute([$programId]);
    if (!$program->fetchColumn()) json(['error' => 'Choose an active program.'], 422);
    $create = $pdo->prepare("INSERT INTO cohorts (program_id, name, code, capacity, status, created_by) VALUES (?, ?, ?, ?, 'planned', ?)");
    $create->execute([$programId, $name, $code, $capacity, $user['id']]);
    $id = (int) $pdo->lastInsertId();
    audit($pdo, (int) $user['id'], 'director_cohort_create', 'cohort', $id, ['program_id' => $programId, 'capacity' => $capacity]);
    json(['ok' => true, 'id' => $id], 201);
}

if ($action === 'cohort_start') {
    $cohortId = filter_var($input['cohortId'] ?? null, FILTER_VALIDATE_INT);
    if (!$cohortId) json(['error' => 'A valid cohort is required.'], 422);
    $start = $pdo->prepare("UPDATE cohorts SET status = 'active', starts_on = CURRENT_DATE, ends_on = DATE_ADD(CURRENT_DATE, INTERVAL 6 MONTH) WHERE id = ? AND status = 'planned'");
    $start->execute([$cohortId]);
    if (!$start->rowCount()) json(['error' => 'Only a planned cohort can be started.'], 409);
    audit($pdo, (int) $user['id'], 'director_cohort_start', 'cohort', $cohortId, ['duration_months' => 6]);
    json(['ok' => true]);
}

if ($action === 'cohort_end') {
    $cohortId = filter_var($input['cohortId'] ?? null, FILTER_VALIDATE_INT);
    if (!$cohortId) json(['error' => 'A valid cohort is required.'], 422);
    $end = $pdo->prepare("UPDATE cohorts SET status = 'closed', ends_on = CURRENT_DATE WHERE id = ? AND status = 'active'");
    $end->execute([$cohortId]);
    if (!$end->rowCount()) json(['error' => 'Only an active cohort can be ended.'], 409);
    audit($pdo, (int) $user['id'], 'director_cohort_end', 'cohort', $cohortId);
    json(['ok' => true]);
}

json(['error' => 'Unknown cohort action.'], 404);
