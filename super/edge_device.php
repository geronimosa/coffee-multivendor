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
$staffAccess = null;
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
        } elseif (isset($_POST['create_staff_access'])) {
            $validDaysInput = trim((string) ($_POST['valid_days'] ?? ''));
            $validDays = $validDaysInput === '' ? null : filter_var($validDaysInput, FILTER_VALIDATE_INT);
            if ($validDaysInput !== '' && $validDays === false) {
                throw new InvalidArgumentException('Key duration must be a whole number of days.');
            }
            $staffAccess = create_edge_staff_access_key(
                $pdo,
                $vendorId,
                (int) $_SESSION['user_id'],
                (string) ($_POST['username'] ?? ''),
                $validDays === null ? null : (int) $validDays
            );
            audit_log($pdo, 'edge.staff_access_created', 'edge_staff_access_key', (string) $staffAccess['id'], $vendorId);
            $message = 'Copy this staff key now. It is shown once; the Pi will receive its hash on the next sync.';
        } elseif (isset($_POST['revoke_staff_access'])) {
            $keyId = filter_input(INPUT_POST, 'staff_key_id', FILTER_VALIDATE_INT);
            if (!$keyId || !revoke_edge_staff_access_key($pdo, $vendorId, (int) $keyId)) {
                throw new RuntimeException('The staff key could not be revoked.');
            }
            audit_log($pdo, 'edge.staff_access_revoked', 'edge_staff_access_key', (string) $keyId, $vendorId);
            $message = 'The staff key has been revoked. The Pi will remove access on its next sync.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Edge administration failed: ' . $exception->getMessage());
        $error = 'Unable to update the Edge device.';
    }
}

$stmt = $pdo->prepare('SELECT device_identifier, device_name, status, software_version, provisioned_at, last_seen_at, last_snapshot_hash, last_order_sync_at, last_reconciliation_at FROM edge_devices WHERE vendor_id=? LIMIT 1');
$stmt->execute([$vendorId]);
$device = $stmt->fetch() ?: null;

$stmt = $pdo->prepare(
    "SELECT id,username,status,expires_at,revoked_at,created_at,
            CASE WHEN status='active' AND (expires_at IS NULL OR expires_at>NOW()) THEN 1 ELSE 0 END AS usable
     FROM edge_staff_access_keys WHERE vendor_id=? ORDER BY created_at DESC,id DESC"
);
$stmt->execute([$vendorId]);
$staffKeys = $stmt->fetchAll();

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

<?php if ($staffAccess): ?>
<section class="card"><h2>New Edge staff key</h2>
    <p>Username: <strong><?= e($staffAccess['username']) ?></strong></p>
    <p><code class="enrollment-key"><?= e($staffAccess['key']) ?></code></p>
    <p class="muted">Give this key only to its named user. It cannot be shown again.</p>
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
        <dt>Last order sync</dt><dd><?= e($device['last_order_sync_at'] ?: 'No orders uploaded yet') ?></dd>
        <dt>Daily reconciliation</dt><dd><?= e($device['last_reconciliation_at'] ?: 'Not run yet') ?></dd>
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

<section class="card"><h2>Local staff portal</h2>
    <p class="muted">Create an individual 10-character key for each person who may use <code>/vendor/<?= e($vendor['slug']) ?></code> on the assigned Pi. Only key hashes synchronize to the Pi.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) $vendorId ?>">
        <div class="grid">
            <div><label for="username">Username</label><input id="username" name="username" minlength="2" maxlength="50" pattern="[A-Za-z0-9._-]+" required placeholder="e.g. steve"></div>
            <div><label for="valid_days">Valid for days <span class="muted">(optional)</span></label><input id="valid_days" type="number" name="valid_days" min="1" max="365" placeholder="Leave blank to never expire"></div>
        </div>
        <button type="submit" name="create_staff_access" value="1">Create staff key</button>
    </form>
</section>

<section class="card"><h2>Staff keys</h2>
<?php if (!$staffKeys): ?><p class="muted">No staff keys have been created.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Staff</th><th>Created</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($staffKeys as $key): ?>
<tr><td><?= e($key['username']) ?></td><td><?= e($key['created_at']) ?></td><td><?= e($key['expires_at'] ?: 'No expiry') ?></td><td><span class="badge <?= (int) $key['usable'] === 1 ? '' : 'suspended' ?>"><?= (int) $key['usable'] === 1 ? 'Active' : ($key['status'] === 'revoked' ? 'Revoked' : 'Expired') ?></span></td><td>
<?php if ((int) $key['usable'] === 1): ?><form method="post" onsubmit="return confirm('Revoke access for <?= e($key['username']) ?>?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) $vendorId ?>"><input type="hidden" name="staff_key_id" value="<?= (int) $key['id'] ?>"><button class="danger-button" type="submit" name="revoke_staff_access" value="1">Revoke</button></form><?php endif; ?>
</td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
