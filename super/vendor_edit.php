<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/integrations.php';
require_super_admin();

$vendorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
$vendor = [
    'name' => '', 'slug' => '', 'status' => 'active', 'contact_email' => '', 'contact_phone' => '',
];
$yoco = null;
$twilio = null;
$owner = null;
$error = null;

if ($vendorId) {
    $stmt = $pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch() ?: redirect('/super/');
    $yoco = integration_for_vendor($pdo, $vendorId, 'yoco');
    $twilio = integration_for_vendor($pdo, $vendorId, 'twilio');
    $stmt = $pdo->prepare("SELECT u.name, u.email FROM users u JOIN restaurant_users ru ON ru.user_id=u.id WHERE ru.restaurant_id=? AND ru.role='admin' ORDER BY ru.id LIMIT 1");
    $stmt->execute([$vendorId]);
    $owner = $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $vendorId = filter_input(INPUT_POST, 'vendor_id', FILTER_VALIDATE_INT) ?: null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    $status = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
    $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
    $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
    $ownerName = trim((string) ($_POST['owner_name'] ?? ''));
    $ownerEmail = strtolower(trim((string) ($_POST['owner_email'] ?? '')));

    if ($name === '' || $slug === '') {
        $error = 'Vendor name and slug are required.';
    } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'The contact email is invalid.';
    } elseif ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'The owner email is invalid.';
    }

    if (!$error && !empty($_POST['yoco_enabled']) && empty($_POST['clear_yoco'])) {
        $existingYoco = $vendorId ? integration_for_vendor($pdo, $vendorId, 'yoco') : null;
        if (!$existingYoco && trim((string) ($_POST['yoco_secret_key'] ?? '')) === '') {
            $error = 'Enter a Yoco secret key before enabling Yoco.';
        }
    }

    if (!$error && !empty($_POST['twilio_enabled']) && empty($_POST['clear_twilio'])) {
        $existingTwilio = $vendorId ? integration_for_vendor($pdo, $vendorId, 'twilio') : null;
        $existingConfig = $existingTwilio ? integration_config($existingTwilio) : [];
        $sid = trim((string) ($_POST['twilio_account_sid'] ?? '')) ?: ($existingConfig['account_sid'] ?? '');
        $token = trim((string) ($_POST['twilio_auth_token'] ?? '')) ?: ($existingConfig['auth_token'] ?? '');
        $from = trim((string) ($_POST['twilio_whatsapp_from'] ?? '')) ?: ($existingConfig['whatsapp_from'] ?? '');
        if ($sid === '' || $token === '' || $from === '') {
            $error = 'Account SID, Auth Token, and WhatsApp sender are required before enabling WhatsApp.';
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();
            if ($vendorId) {
                $stmt = $pdo->prepare('UPDATE restaurants SET name=?, slug=?, status=?, contact_email=?, contact_phone=? WHERE id=?');
                $stmt->execute([$name, $slug, $status, $contactEmail ?: null, $contactPhone ?: null, $vendorId]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM restaurants WHERE id=?');
                    $check->execute([$vendorId]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Vendor not found.');
                    }
                }
                $action = 'vendor.updated';
            } else {
                $stmt = $pdo->prepare('INSERT INTO restaurants (name, slug, status, contact_email, contact_phone, unique_code, uid, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $slug, $status, $contactEmail ?: null, $contactPhone ?: null, bin2hex(random_bytes(12)), bin2hex(random_bytes(24)), $_SESSION['user_id']]);
                $vendorId = (int) $pdo->lastInsertId();
                $action = 'vendor.created';
            }

            if (!empty($_POST['clear_yoco'])) {
                delete_vendor_integration($pdo, $vendorId, 'yoco');
            } else {
                save_vendor_integration($pdo, $vendorId, 'yoco', ($_POST['yoco_environment'] ?? '') === 'live' ? 'live' : 'test', !empty($_POST['yoco_enabled']), [
                    'secret_key' => $_POST['yoco_secret_key'] ?? '',
                ]);
            }

            if (!empty($_POST['clear_twilio'])) {
                delete_vendor_integration($pdo, $vendorId, 'twilio');
            } else {
                save_vendor_integration($pdo, $vendorId, 'twilio', 'live', !empty($_POST['twilio_enabled']), [
                    'account_sid' => $_POST['twilio_account_sid'] ?? '',
                    'auth_token' => $_POST['twilio_auth_token'] ?? '',
                    'whatsapp_from' => $_POST['twilio_whatsapp_from'] ?? '',
                    'content_sid_order_ready' => $_POST['twilio_content_sid_order_ready'] ?? '',
                ]);
            }

            if ($ownerEmail !== '') {
                $stmt = $pdo->prepare('INSERT INTO users (email, name, role, active) VALUES (?, ?, \'restaurant_user\', 1) ON DUPLICATE KEY UPDATE name=VALUES(name), active=1');
                $stmt->execute([$ownerEmail, $ownerName ?: null]);
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
                $stmt->execute([$ownerEmail]);
                $ownerId = (int) $stmt->fetchColumn();
                $stmt = $pdo->prepare('SELECT id FROM restaurant_users WHERE restaurant_id=? AND user_id=? LIMIT 1');
                $stmt->execute([$vendorId, $ownerId]);
                $assignmentId = $stmt->fetchColumn();
                if ($assignmentId) {
                    $pdo->prepare("UPDATE restaurant_users SET role='admin' WHERE id=?")->execute([$assignmentId]);
                } else {
                    $pdo->prepare("INSERT INTO restaurant_users (restaurant_id, user_id, role) VALUES (?, ?, 'admin')")->execute([$vendorId, $ownerId]);
                }
            }

            audit_log($pdo, $action, 'vendor', (string) $vendorId, $vendorId, [
                'status' => $status,
                'yoco_configured' => integration_for_vendor($pdo, $vendorId, 'yoco') !== null,
                'twilio_configured' => integration_for_vendor($pdo, $vendorId, 'twilio') !== null,
            ]);
            $pdo->commit();
            $_SESSION['flash'] = 'Vendor saved.';
            redirect('/super/vendor_edit.php?id=' . $vendorId);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $error = $exception->getCode() === '23000' ? 'That vendor slug or owner assignment is already in use.' : 'Unable to save the vendor.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $error = 'Unable to save the vendor configuration.';
        }
    }

    $vendor = compact('name', 'slug', 'status') + ['contact_email' => $contactEmail, 'contact_phone' => $contactPhone];
    $owner = ['name' => $ownerName, 'email' => $ownerEmail];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = $vendorId ? 'Edit vendor' : 'Add vendor';
require __DIR__ . '/_header.php';
?>
<div class="actions"><a class="button secondary" href="/super/">← Vendors</a><h1><?= e($pageTitle) ?></h1></div>
<?php if ($flash): ?><div class="notice"><?= e($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) ($vendorId ?: 0) ?>">
<section class="card"><h2>Vendor details</h2><div class="grid">
    <div><label for="name">Business name</label><input id="name" name="name" value="<?= e($vendor['name']) ?>" required></div>
    <div><label for="slug">Vendor slug</label><input id="slug" name="slug" value="<?= e($vendor['slug']) ?>" pattern="[a-z0-9-]+" required></div>
    <div><label for="contact_email">Contact email</label><input id="contact_email" type="email" name="contact_email" value="<?= e($vendor['contact_email']) ?>"></div>
    <div><label for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" value="<?= e($vendor['contact_phone']) ?>"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="active" <?= $vendor['status']==='active'?'selected':'' ?>>Active</option><option value="suspended" <?= $vendor['status']==='suspended'?'selected':'' ?>>Suspended</option></select></div>
</div></section>
<section class="card"><h2>Vendor owner</h2><p class="muted">The owner will be assigned as this vendor's administrator.</p><div class="grid">
    <div><label for="owner_name">Owner name</label><input id="owner_name" name="owner_name" value="<?= e($owner['name'] ?? '') ?>"></div>
    <div><label for="owner_email">Owner email</label><input id="owner_email" type="email" name="owner_email" value="<?= e($owner['email'] ?? '') ?>"></div>
</div></section>
<section class="card"><h2>Yoco</h2><p class="muted">Saved credentials: <?= e($yoco['config_hint'] ?? 'Not configured') ?>. Leave the key blank to retain it.</p><div class="grid">
    <div><label for="yoco_environment">Environment</label><select id="yoco_environment" name="yoco_environment"><option value="test" <?= ($yoco['environment'] ?? 'test')==='test'?'selected':'' ?>>Test</option><option value="live" <?= ($yoco['environment'] ?? '')==='live'?'selected':'' ?>>Live</option></select></div>
    <div><label for="yoco_secret_key">Secret key</label><input id="yoco_secret_key" type="password" name="yoco_secret_key" autocomplete="new-password"></div>
    <div><label><input type="checkbox" name="yoco_enabled" value="1" <?= !empty($yoco['enabled'])?'checked':'' ?>> Enable Yoco</label></div>
    <?php if ($yoco): ?><div><label><input type="checkbox" name="clear_yoco" value="1"> Remove saved Yoco credentials</label></div><?php endif; ?>
</div></section>
<section class="card"><h2>WhatsApp via Twilio</h2><p class="muted">Saved credentials: <?= e($twilio['config_hint'] ?? 'Not configured') ?>. Blank fields retain saved values.</p><div class="grid">
    <div><label for="twilio_account_sid">Account SID</label><input id="twilio_account_sid" type="password" name="twilio_account_sid" autocomplete="new-password"></div>
    <div><label for="twilio_auth_token">Auth token</label><input id="twilio_auth_token" type="password" name="twilio_auth_token" autocomplete="new-password"></div>
    <div><label for="twilio_whatsapp_from">WhatsApp sender</label><input id="twilio_whatsapp_from" name="twilio_whatsapp_from" placeholder="whatsapp:+27..."></div>
    <div><label for="twilio_content_sid_order_ready">Order-ready template SID</label><input id="twilio_content_sid_order_ready" name="twilio_content_sid_order_ready"></div>
    <div><label><input type="checkbox" name="twilio_enabled" value="1" <?= !empty($twilio['enabled'])?'checked':'' ?>> Enable WhatsApp</label></div>
    <?php if ($twilio): ?><div><label><input type="checkbox" name="clear_twilio" value="1"> Remove saved WhatsApp credentials</label></div><?php endif; ?>
</div></section>
<div class="actions"><button type="submit">Save vendor</button><a class="button secondary" href="/super/">Cancel</a></div>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
