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
    fwrite(STDERR, "Usage: php scripts/create_super_admin_setup.php email@example.com [name]\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO users (email, name, role, active) VALUES (?, ?, 'super_admin', 1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), role='super_admin', active=1"
    );
    $stmt->execute([$email, $name]);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
    $stmt->execute([$email]);
    $userId = (int) $stmt->fetchColumn();
    $pdo->prepare('UPDATE password_setup_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO password_setup_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
    $stmt->execute([$userId, hash('sha256', $token)]);
    $pdo->commit();

    $baseUrl = rtrim((string) env('APP_URL', 'https://coffee.tatu.co.za'), '/');
    echo $baseUrl . '/super/set_password.php?token=' . urlencode($token) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
