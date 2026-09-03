<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--confirm-disposable') {
    fwrite(STDERR, "Usage: php tests/migration_smoke.php --confirm-disposable\n");
    exit(1);
}

require_once __DIR__ . '/../includes/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/db.php';

$sourceDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'coffee_multivendor_test_' . gmdate('Ymd_His');
if (!preg_match('/^coffee_multivendor_test_[0-9_]+$/', $testDatabase)) {
    throw new RuntimeException('Unsafe disposable database name.');
}

try {
    $pdo->exec("CREATE DATABASE `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $appliedMigrations = in_array('schema_migrations', $tables, true)
        ? $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
        : [];
    $pdo->exec("USE `$testDatabase`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $safeTable = str_replace('`', '``', (string) $table);
        $row = $pdo->query("SHOW CREATE TABLE `$sourceDatabase`.`$safeTable`")->fetch(PDO::FETCH_NUM);
        // MariaDB requires InnoDB foreign-key constraint names to be unique even
        // while the source schema remains present. Give cloned constraints an
        // isolated name without changing their columns or reference behaviour.
        $cloneSql = preg_replace_callback(
            '/CONSTRAINT `([^`]+)`/',
            static fn(array $match): string => 'CONSTRAINT `' . substr($match[1] . '_smoke_' . hash('sha256', $testDatabase . $table), 0, 60) . '`',
            $row[1]
        );
        $pdo->exec($cloneSql);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    foreach (glob(__DIR__ . '/../migrations/*.sql') ?: [] as $migrationFile) {
        if (in_array(basename($migrationFile), $appliedMigrations, true)) continue;
        $migration = file_get_contents($migrationFile);
        if ($migration === false) throw new RuntimeException('Migration not found: ' . $migrationFile);
        $pdo->exec($migration);
    }

    foreach (['vendor_integrations', 'audit_logs', 'password_setup_tokens', 'edge_devices', 'edge_enrollment_tokens', 'edge_staff_access_keys', 'edge_order_events', 'dining_tables', 'table_tabs', 'tab_guests', 'tab_payments'] as $requiredTable) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $stmt->execute([$testDatabase, $requiredTable]);
        if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException('Missing table: ' . $requiredTable);
    }
    foreach ([['restaurants', 'slug'], ['restaurants', 'theme_primary'], ['restaurants', 'hero_path'], ['restaurants', 'vendor_description'], ['restaurants', 'service_model'], ['users', 'password_hash'], ['orders', 'payment_status'], ['orders', 'order_uuid'], ['orders', 'table_tab_id'], ['order_items', 'origin_line_id'], ['order_items', 'item_note'], ['edge_staff_access_keys', 'username'], ['edge_staff_access_keys', 'key_hash'], ['edge_devices', 'last_reconciliation_at']] as [$table, $column]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?');
        $stmt->execute([$testDatabase, $table, $column]);
        if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException("Missing column: $table.$column");
    }
    echo "Migration smoke test passed.\n";
} finally {
    $pdo->exec("USE `$sourceDatabase`");
    $pdo->exec("DROP DATABASE IF EXISTS `$testDatabase`");
    echo "Disposable database removed.\n";
}
