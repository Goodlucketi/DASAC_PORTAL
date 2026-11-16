<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student']);
$pdo = db();
$sid = studentId($pdo, (int) $user['id']);
$statement = $pdo->prepare("SELECT c.serial, c.title, c.status, p.name AS program, s.legal_name AS recipient FROM certificates c JOIN students s ON s.id = c.student_id JOIN programs p ON p.id = s.program_id WHERE c.student_id = ? ORDER BY c.id DESC");
$statement->execute([$sid]);
json(['certificates' => $statement->fetchAll()]);