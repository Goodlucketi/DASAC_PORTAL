<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
$user = requireRole(['student', 'instructor', 'hod', 'director', 'superadmin']);
json(['user' => $user]);