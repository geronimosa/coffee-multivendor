<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/edge_devices.php';

function edge_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$encoded = edge_base64url_encode(random_bytes(24));
edge_assert((bool) preg_match('/^[A-Za-z0-9_-]{32}$/', $encoded), 'Enrollment token encoding is invalid.');

$secret = edge_base64url_encode(random_bytes(32));
$body = '{"snapshot":{"schema_version":1}}';
$signature = edge_sign_payload($body, $secret);
edge_assert(strlen($signature) === 64, 'Signature length is invalid.');
edge_assert(hash_equals($signature, hash_hmac('sha256', $body, $secret)), 'Signature verification failed.');
edge_assert(!hash_equals($signature, edge_sign_payload($body . 'x', $secret)), 'Changed payload was accepted.');
edge_assert(!hash_equals(edge_secret_hash($secret), edge_secret_hash($secret . 'x')), 'Secret hashes must differ.');

$staffKey = generate_edge_staff_key();
edge_assert((bool) preg_match('/^[A-HJ-NP-Za-km-z2-9]{10}$/', $staffKey), 'Staff key format is invalid.');

echo "Edge protocol test passed.\n";
