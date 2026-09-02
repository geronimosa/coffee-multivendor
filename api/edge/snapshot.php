<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/edge_devices.php';
require_once __DIR__ . '/../../includes/rich_text.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    edge_json_response(['error' => 'method_not_allowed'], 405);
}

$device = edge_authorized_device($pdo);
if (!$device) {
    edge_json_response(['error' => 'unauthorized'], 401);
}

$stmt = $pdo->prepare(
    'SELECT id, name, slug, status, contact_email, contact_phone, theme_primary, theme_accent,
            theme_background, theme_surface, theme_text, logo_path, hero_path, storefront_message,
            vendor_description, updated_at
     FROM restaurants WHERE id=? AND status=\'active\' LIMIT 1'
);
$stmt->execute([(int) $device['vendor_id']]);
$vendor = $stmt->fetch();
if (!$vendor) {
    edge_json_response(['error' => 'vendor_unavailable'], 409, $device['device_secret']);
}
$vendor['id'] = (int) $vendor['id'];
$vendor['vendor_description'] = sanitize_vendor_description($vendor['vendor_description'] ?? '');

$stmt = $pdo->prepare('SELECT id, name, category, price, variant_options FROM menu_items WHERE restaurant_id=? ORDER BY category, name, id');
$stmt->execute([(int) $device['vendor_id']]);
$items = [];
foreach ($stmt->fetchAll() as $item) {
    $items[] = [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'category' => (string) ($item['category'] ?? ''),
        'price' => number_format((float) $item['price'], 2, '.', ''),
        'variants' => json_decode($item['variant_options'] ?: '[]', true) ?: [],
    ];
}

$stmt = $pdo->prepare(
    "SELECT id,username,key_hash,UNIX_TIMESTAMP(expires_at) AS expires_at_epoch FROM edge_staff_access_keys
     WHERE vendor_id=? AND status='active' AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id"
);
$stmt->execute([(int) $device['vendor_id']]);
$staffAccessKeys = array_map(static fn(array $key): array => [
    'id' => (int) $key['id'],
    'username' => (string) $key['username'],
    'key_hash' => (string) $key['key_hash'],
    'expires_at_epoch' => $key['expires_at_epoch'] === null ? null : (int) $key['expires_at_epoch'],
], $stmt->fetchAll());

$snapshot = [
    'schema_version' => 1,
    'generated_at' => gmdate('c'),
    'staff_access_keys' => $staffAccessKeys,
    'vendor' => $vendor,
    'menu_items' => $items,
];
$snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$snapshot['snapshot_hash'] = $snapshotHash;

$pdo->prepare('UPDATE edge_devices SET last_seen_at=NOW(), last_snapshot_hash=? WHERE id=?')->execute([$snapshotHash, $device['id']]);
edge_json_response(['snapshot' => $snapshot], 200, $device['device_secret']);
