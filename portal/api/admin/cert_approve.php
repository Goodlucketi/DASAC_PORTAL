<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['director']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json(['error' => 'Method not allowed.'], 405);
}
$input = requestJson();
$decision = (string) ($input['decision'] ?? '');
$certificateIds = $input['certificate_ids'] ?? [$input['certificate_id'] ?? null];
if (!is_array($certificateIds) || count($certificateIds) < 1 || count($certificateIds) > 100) {
    json(['error' => 'Select between 1 and 100 certificates.'], 422);
}
$certificateIds = array_values(array_unique(array_filter(array_map(
    static fn($id): int|false => filter_var($id, FILTER_VALIDATE_INT),
    $certificateIds
), static fn($id): bool => $id !== false && $id > 0)));
$reason = trim((string) ($input['reason'] ?? ''));
if (!$certificateIds || !in_array($decision, ['approve', 'reject', 'revoke'], true)) {
    json(['error' => 'A valid certificate selection and decision are required.'], 422);
}
if (in_array($decision, ['reject', 'revoke'], true) && mb_strlen($reason) < 5) {
    json(['error' => 'A decision reason of at least 5 characters is required.'], 422);
}
$fromStatus = $decision === 'revoke' ? 'cleared' : 'pending';
$status = ['approve' => 'cleared', 'reject' => 'rejected', 'revoke' => 'revoked'][$decision];
$pdo = db();
$placeholders = implode(',', array_fill(0, count($certificateIds), '?'));
$pdo->beginTransaction();
try {
    $update = $pdo->prepare("UPDATE certificates SET status = ?, approved_by = ?, approved_at = NOW() WHERE id IN ($placeholders) AND status = ?");
    $update->execute(array_merge([$status, $user['id']], $certificateIds, [$fromStatus]));
    if ($update->rowCount() !== count($certificateIds)) {
        $pdo->rollBack();
        json(['error' => 'One or more selected certificates are no longer available for this decision.'], 409);
    }
    foreach ($certificateIds as $certificateId) {
        audit($pdo, (int) $user['id'], 'certificate_' . $decision, 'certificate', $certificateId, $reason === '' ? null : ['reason' => $reason]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
json(['ok' => true, 'updated' => count($certificateIds)]);