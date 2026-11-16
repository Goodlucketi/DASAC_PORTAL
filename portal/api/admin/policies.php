<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/policies.php';

$user = requireRole(['director', 'superadmin']);
json(['policy' => loadPolicy()]);
