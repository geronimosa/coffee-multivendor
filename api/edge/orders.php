<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/edge_devices.php';
require_once __DIR__ . '/../../includes/edge_orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    edge_json_response(['error' => 'method_not_allowed'], 405);
}

$device = edge_authorized_device($pdo);
if (!$device) edge_json_response(['error' => 'unauthorized'], 401);

$body = file_get_contents('php://input');
$signature = trim((string) ($_SERVER['HTTP_X_QRKIOSK_PAYLOAD_SIGNATURE'] ?? ''));
if ($body === false || strlen($body) > 1048576 || !preg_match('/^[a-f0-9]{64}$/', $signature)
    || !hash_equals(edge_sign_payload($body, $device['device_secret']), $signature)) {
    edge_json_response(['error' => 'invalid_payload_signature'], 401, $device['device_secret']);
}

try {
    $input = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid Edge request.');
    $accepted = sync_edge_orders($pdo, $device, $input);
    edge_json_response(['accepted' => $accepted, 'received_at' => gmdate('c')], 200, $device['device_secret']);
} catch (InvalidArgumentException $exception) {
    edge_json_response(['error' => 'invalid_order_batch'], 422, $device['device_secret']);
} catch (Throwable $exception) {
    error_log('Edge order sync failed: ' . $exception->getMessage());
    edge_json_response(['error' => 'order_sync_failed'], 500, $device['device_secret']);
}
