<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/db.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
$name = trim((string) ($argv[2] ?? 'Super Admin'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_super_admin.php email@example.com [name]\n");
    exit(1);
}

fwrite(STDOUT, 'Password: ');
if (function_exists('shell_exec')) {
    shell_exec('stty -echo');
}
$password = trim((string) fgets(STDIN));
if (function_exists('shell_exec')) {
    shell_exec('stty echo');
}
fwrite(STDOUT, PHP_EOL);

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    "INSERT INTO users (email, name, password_hash, role, active) VALUES (?, ?, ?, 'super_admin', 1)
     ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role='super_admin', active=1"
);
$stmt->execute([$email, $name, $hash]);
echo "Super Admin account created or updated.\n";
