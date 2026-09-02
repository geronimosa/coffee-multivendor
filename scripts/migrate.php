<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/db.php';

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

foreach ($files as $file) {
    $migration = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration: ' . $file);
    }
    $checksum = hash('sha256', $sql);
    $stmt = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
    $stmt->execute([$migration]);
    $appliedChecksum = $stmt->fetchColumn();
    if ($appliedChecksum !== false) {
        if (!hash_equals((string) $appliedChecksum, $checksum)) {
            throw new RuntimeException('Applied migration was modified: ' . $migration);
        }
        echo 'Already applied: ', $migration, PHP_EOL;
        continue;
    }

    echo 'Applying ', $migration, PHP_EOL;
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)')->execute([$migration, $checksum]);
}
echo "Migrations complete.\n";
