<?php
declare(strict_types=1);

function integration_for_vendor(PDO $pdo, int $vendorId, string $provider): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vendor_integrations WHERE vendor_id = ? AND provider = ? LIMIT 1');
    $stmt->execute([$vendorId, $provider]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function integration_config(?array $integration): array
{
    if (!$integration || empty($integration['encrypted_config'])) {
        return [];
    }
    return decrypt_secret_array($integration['encrypted_config']);
}

function save_vendor_integration(PDO $pdo, int $vendorId, string $provider, string $environment, bool $enabled, array $newValues): void
{
    $existing = integration_for_vendor($pdo, $vendorId, $provider);
    $config = integration_config($existing);

    foreach ($newValues as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $config[$key] = $value;
        }
    }

    if (!$config) {
        return;
    }

    $hintSource = $provider === 'yoco'
        ? ($config['secret_key'] ?? '')
        : ($config['account_sid'] ?? '');
    $encrypted = encrypt_secret_array($config);
    $hint = secret_hint($hintSource);

    $stmt = $pdo->prepare(
        'INSERT INTO vendor_integrations (vendor_id, provider, environment, encrypted_config, config_hint, enabled)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE environment = VALUES(environment), encrypted_config = VALUES(encrypted_config),
            config_hint = VALUES(config_hint), enabled = VALUES(enabled)'
    );
    $stmt->execute([$vendorId, $provider, $environment, $encrypted, $hint, $enabled ? 1 : 0]);
}

function delete_vendor_integration(PDO $pdo, int $vendorId, string $provider): void
{
    $stmt = $pdo->prepare('DELETE FROM vendor_integrations WHERE vendor_id = ? AND provider = ?');
    $stmt->execute([$vendorId, $provider]);
}
