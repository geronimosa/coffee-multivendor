<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/edge_devices.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    edge_json_response(['error' => 'method_not_allowed'], 405);
}

$input = edge_json_input();
$token = trim((string) ($input['enrollment_key'] ?? ''));
$deviceName = substr(trim((string) ($input['device_name'] ?? '')), 0, 120);
$softwareVersion = substr(trim((string) ($input['software_version'] ?? '')), 0, 50);

try {
    $credentials = enroll_edge_device($pdo, $token, $deviceName, $softwareVersion);
    if (!$credentials) {
        usleep(random_int(100000, 300000));
        edge_json_response(['error' => 'invalid_or_expired_enrollment_key'], 401);
    }
    try {
        audit_log($pdo, 'edge.provisioned', 'edge_device', $credentials['device_id'], $credentials['vendor_id'], [
            'device_name' => $deviceName,
        ]);
    } catch (Throwable $auditException) {
        error_log('Edge enrollment audit failed: ' . $auditException->getMessage());
    }
    unset($credentials['vendor_id']);
    edge_json_response(['edge' => $credentials], 201);
} catch (Throwable $exception) {
    error_log('Edge enrollment failed: ' . $exception->getMessage());
    edge_json_response(['error' => 'enrollment_failed'], 500);
}
