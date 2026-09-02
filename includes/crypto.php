<?php
declare(strict_types=1);

function application_key(): string
{
    $encoded = env('APP_KEY', '');
    $key = base64_decode((string) $encoded, true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
    }
    return $key;
}

function encrypt_secret_array(array $data): string
{
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($json, $nonce, application_key());
    return base64_encode($nonce . $ciphertext);
}

function decrypt_secret_array(string $payload): array
{
    $decoded = base64_decode($payload, true);
    if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Invalid encrypted integration payload.');
    }

    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, application_key());
    if ($plaintext === false) {
        throw new RuntimeException('Unable to decrypt integration payload.');
    }

    $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : [];
}

function secret_hint(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not configured';
    }
    return str_repeat('•', 8) . substr($value, -4);
}
