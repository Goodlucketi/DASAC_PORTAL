<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['superadmin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$requestId = filter_var($input['request_id'] ?? null, FILTER_VALIDATE_INT);
$decision = (string) ($input['decision'] ?? '');
if (!$requestId || !in_array($decision, ['approve', 'reject'], true)) {
    json(['error' => 'A valid request and decision are required.'], 422);
}
$pdo = db();
$status = $decision === 'approve' ? 'approved' : 'rejected';
$pdo->beginTransaction();
try {
    $lookup = $pdo->prepare('SELECT pcr.student_id, pcr.field_name, pcr.requested_value FROM profile_change_requests pcr WHERE pcr.id = ? AND pcr.status = ? FOR UPDATE');
    $lookup->execute([$requestId, 'pending']);
    $request = $lookup->fetch();
    if (!$request) {
        $pdo->rollBack();
        json(['error' => 'Pending correction request was not found.'], 404);
    }
    if ($decision === 'approve') {
        $columns = ['legal_name' => 'legal_name', 'date_of_birth' => 'date_of_birth', 'gender' => 'gender'];
        $column = $columns[$request['field_name']];
        $apply = $pdo->prepare("UPDATE students SET {$column} = ? WHERE id = ?");
        $apply->execute([$request['requested_value'], $request['student_id']]);
        if ($column === 'legal_name') {
            $userUpdate = $pdo->prepare('UPDATE users u JOIN students s ON s.user_id = u.id SET u.full_name = ? WHERE s.id = ?');
            $userUpdate->execute([$request['requested_value'], $request['student_id']]);
        }
    }
    $update = $pdo->prepare('UPDATE profile_change_requests SET status = ? WHERE id = ? AND status = ?');
    $update->execute([$status, $requestId, 'pending']);
    audit($pdo, (int) $user['id'], 'profile_correction_' . $decision, 'profile_change_request', $requestId);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true]);