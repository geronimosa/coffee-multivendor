<?php
declare(strict_types=1);

function edge_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function edge_secret_hash(string $secret): string
{
    return hash('sha256', $secret);
}

function edge_sign_payload(string $body, string $secret): string
{
    return hash_hmac('sha256', $body, $secret);
}

function create_edge_enrollment_token(PDO $pdo, int $vendorId, int $userId): array
{
    $token = 'edge_' . edge_base64url_encode(random_bytes(24));
    $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE edge_enrollment_tokens SET used_at=NOW() WHERE vendor_id=? AND used_at IS NULL')->execute([$vendorId]);
        $stmt = $pdo->prepare('INSERT INTO edge_enrollment_tokens (vendor_id, token_hash, expires_at, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$vendorId, edge_secret_hash($token), $expiresAt, $userId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    return ['token' => $token, 'expires_at' => $expiresAt];
}

function enroll_edge_device(PDO $pdo, string $token, string $deviceName, string $softwareVersion): ?array
{
    if (!preg_match('/^edge_[A-Za-z0-9_-]{32}$/', $token)) {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT et.id, et.vendor_id, r.slug
             FROM edge_enrollment_tokens et JOIN restaurants r ON r.id=et.vendor_id
             WHERE et.token_hash=? AND et.used_at IS NULL AND et.expires_at>NOW() AND r.status=\'active\'
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([edge_secret_hash($token)]);
        $enrollment = $stmt->fetch();
        if (!$enrollment) {
            $pdo->rollBack();
            return null;
        }

        $deviceIdentifier = bin2hex(random_bytes(16));
        $deviceSecret = edge_base64url_encode(random_bytes(32));
        $encryptedCredential = encrypt_secret_array(['device_secret' => $deviceSecret]);
        $stmt = $pdo->prepare(
            'INSERT INTO edge_devices
                (vendor_id, device_identifier, device_name, credential_hash, encrypted_credential, status, software_version, provisioned_at)
             VALUES (?, ?, ?, ?, ?, \'active\', ?, NOW())
             ON DUPLICATE KEY UPDATE device_identifier=VALUES(device_identifier), device_name=VALUES(device_name),
                credential_hash=VALUES(credential_hash), encrypted_credential=VALUES(encrypted_credential), status=\'active\',
                software_version=VALUES(software_version), provisioned_at=NOW(), revoked_at=NULL, last_snapshot_hash=NULL'
        );
        $stmt->execute([
            (int) $enrollment['vendor_id'], $deviceIdentifier, $deviceName ?: null,
            edge_secret_hash($deviceSecret), $encryptedCredential, $softwareVersion ?: null,
        ]);
        $pdo->prepare('UPDATE edge_enrollment_tokens SET used_at=NOW() WHERE id=?')->execute([$enrollment['id']]);
        $pdo->commit();

        return [
            'device_id' => $deviceIdentifier,
            'device_secret' => $deviceSecret,
            'vendor_id' => (int) $enrollment['vendor_id'],
            'vendor_slug' => $enrollment['slug'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function edge_authorized_device(PDO $pdo): ?array
{
    $deviceIdentifier = trim((string) ($_SERVER['HTTP_X_QRKIOSK_DEVICE'] ?? ''));
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $deviceIdentifier) || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM edge_devices WHERE device_identifier=? AND status=\'active\' LIMIT 1');
    $stmt->execute([$deviceIdentifier]);
    $device = $stmt->fetch();
    if (!$device || !hash_equals((string) $device['credential_hash'], edge_secret_hash($matches[1]))) {
        return null;
    }

    $credentials = decrypt_secret_array((string) $device['encrypted_credential']);
    if (!isset($credentials['device_secret']) || !hash_equals((string) $credentials['device_secret'], $matches[1])) {
        return null;
    }
    $device['device_secret'] = $matches[1];
    return $device;
}

function edge_json_input(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 16384) {
        return [];
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (JsonException) {
        return [];
    }
}

function edge_json_response(array $payload, int $status = 200, ?string $secret = null): never
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($secret !== null) {
        header('X-QRKiosk-Signature: ' . edge_sign_payload($body, $secret));
    }
    echo $body;
    exit;
}
