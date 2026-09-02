<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/edge_devices.php';
require_super_admin();

$vendorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'vendor_id', FILTER_VALIDATE_INT);
if (!$vendorId) redirect('/super/');

$stmt = $pdo->prepare('SELECT id, name, slug FROM restaurants WHERE id=? LIMIT 1');
$stmt->execute([$vendorId]);
$vendor = $stmt->fetch();
if (!$vendor) redirect('/super/');

$enrollment = null;
$message = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        if (isset($_POST['generate_enrollment'])) {
            $enrollment = create_edge_enrollment_token($pdo, $vendorId, (int) $_SESSION['user_id']);
            audit_log($pdo, 'edge.enrollment_created', 'vendor', (string) $vendorId, $vendorId);
            $message = 'Copy this enrollment key now. It is shown once and expires in 30 minutes.';
        } elseif (isset($_POST['revoke_device'])) {
            $pdo->prepare("UPDATE edge_devices SET status='revoked', revoked_at=NOW() WHERE vendor_id=?")->execute([$vendorId]);
            audit_log($pdo, 'edge.revoked', 'vendor', (string) $vendorId, $vendorId);
            $message = 'The Edge device has been revoked.';
        }
    } catch (Throwable $exception) {
        error_log('Edge administration failed: ' . $exception->getMessage());
        $error = 'Unable to update the Edge device.';
    }
}

$stmt = $pdo->prepare('SELECT device_identifier, device_name, status, software_version, provisioned_at, last_seen_at, last_snapshot_hash FROM edge_devices WHERE vendor_id=? LIMIT 1');
$stmt->execute([$vendorId]);
$device = $stmt->fetch() ?: null;

$pageTitle = 'Edge device';
require __DIR__ . '/_header.php';
?>
<div class="actions"><a class="button secondary" href="/super/">← Vendors</a><h1><?= e($vendor['name']) ?> Edge device</h1></div>
<?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<?php if ($enrollment): ?>
<section class="card"><h2>One-time enrollment key</h2>
    <p><code class="enrollment-key"><?= e($enrollment['token']) ?></code></p>
    <p class="muted">Expires at <?= e($enrollment['expires_at']) ?> server time. Generating another key invalidates this one.</p>
</section>
<?php endif; ?>

<section class="card"><h2>Device status</h2>
<?php if ($device): ?>
    <dl class="device-details">
        <dt>Status</dt><dd><span class="badge <?= $device['status'] === 'revoked' ? 'suspended' : '' ?>"><?= e(ucfirst($device['status'])) ?></span></dd>
        <dt>Name</dt><dd><?= e($device['device_name'] ?: 'Unnamed device') ?></dd>
        <dt>Device ID</dt><dd><code><?= e($device['device_identifier']) ?></code></dd>
        <dt>Software</dt><dd><?= e($device['software_version'] ?: 'Unknown') ?></dd>
        <dt>Provisioned</dt><dd><?= e($device['provisioned_at']) ?></dd>
        <dt>Last seen</dt><dd><?= e($device['last_seen_at'] ?: 'Never') ?></dd>
    </dl>
<?php else: ?><p class="muted">No Raspberry Pi has been enrolled for vendor slug <code><?= e($vendor['slug']) ?></code>.</p><?php endif; ?>
</section>

<section class="card"><h2>Enrollment</h2>
    <p class="muted">Only one Pi can be active for this vendor. Enrolling again rotates the device identity and secret.</p>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) $vendorId ?>">
        <button type="submit" name="generate_enrollment" value="1">Generate one-time key</button>
    </form>
    <?php if ($device && $device['status'] === 'active'): ?>
    <form method="post" style="margin-top:1rem" onsubmit="return confirm('Revoke this Edge device? It will no longer be able to synchronize.');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) $vendorId ?>">
        <button class="danger-button" type="submit" name="revoke_device" value="1">Revoke device</button>
    </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
