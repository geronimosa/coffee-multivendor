<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/integrations.php';
require_once __DIR__ . '/../includes/vendor_theme.php';
require_once __DIR__ . '/../includes/rich_text.php';
require_super_admin();

$vendorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
$vendor = [
    'name' => '', 'slug' => '', 'status' => 'active', 'contact_email' => '', 'contact_phone' => '',
    'theme_primary' => '#1F4D3A', 'theme_accent' => '#F2B84B', 'theme_background' => '#F7F5F0',
    'theme_surface' => '#FFFFFF', 'theme_text' => '#17211C', 'logo_path' => null, 'hero_path' => null,
    'storefront_message' => 'Order ahead and collect when it is ready.', 'vendor_description' => '',
];
$yoco = null;
$snapscan = null;
$zapper = null;
$twilio = null;
$owner = null;
$error = null;

if ($vendorId) {
    $stmt = $pdo->prepare('SELECT * FROM restaurants WHERE id = ?');
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch() ?: redirect('/super/');
    $yoco = integration_for_vendor($pdo, $vendorId, 'yoco');
    $snapscan = integration_for_vendor($pdo, $vendorId, 'snapscan');
    $zapper = integration_for_vendor($pdo, $vendorId, 'zapper');
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
    $themePrimary = valid_hex_color($_POST['theme_primary'] ?? null, '#1F4D3A');
    $themeAccent = valid_hex_color($_POST['theme_accent'] ?? null, '#F2B84B');
    $themeBackground = valid_hex_color($_POST['theme_background'] ?? null, '#F7F5F0');
    $themeSurface = valid_hex_color($_POST['theme_surface'] ?? null, '#FFFFFF');
    $themeText = valid_hex_color($_POST['theme_text'] ?? null, '#17211C');
    $storefrontMessage = trim((string) ($_POST['storefront_message'] ?? ''));
    try {
        $vendorDescription = sanitize_vendor_description($_POST['vendor_description'] ?? '');
    } catch (InvalidArgumentException $exception) {
        $vendorDescription = '';
        $error = $exception->getMessage();
    }

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

    if (!$error && !empty($_POST['snapscan_enabled']) && empty($_POST['clear_snapscan'])) {
        $existing = $vendorId ? integration_for_vendor($pdo, $vendorId, 'snapscan') : null;
        $config = $existing ? integration_config($existing) : [];
        $snapCode = trim((string) ($_POST['snapscan_snap_code'] ?? '')) ?: ($config['snap_code'] ?? '');
        $apiKey = trim((string) ($_POST['snapscan_api_key'] ?? '')) ?: ($config['api_key'] ?? '');
        if ($snapCode === '' || $apiKey === '') $error = 'SnapCode and API key are required before enabling SnapScan.';
    }

    if (!$error && !empty($_POST['zapper_enabled']) && empty($_POST['clear_zapper'])) {
        $existing = $vendorId ? integration_for_vendor($pdo, $vendorId, 'zapper') : null;
        $config = $existing ? integration_config($existing) : [];
        $merchantId = trim((string) ($_POST['zapper_merchant_id'] ?? '')) ?: ($config['merchant_id'] ?? '');
        $siteId = trim((string) ($_POST['zapper_site_id'] ?? '')) ?: ($config['site_id'] ?? '');
        $apiKey = trim((string) ($_POST['zapper_api_key'] ?? '')) ?: ($config['api_key'] ?? '');
        if ($merchantId === '' || $siteId === '' || $apiKey === '') $error = 'Merchant ID, Site ID, and API key are required before enabling Zapper.';
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

            $logoPath = store_vendor_image($_FILES['logo'] ?? [], $vendorId, 'logo') ?: ($vendor['logo_path'] ?? null);
            $heroPath = store_vendor_image($_FILES['hero'] ?? [], $vendorId, 'hero') ?: ($vendor['hero_path'] ?? null);
            $stmt = $pdo->prepare('UPDATE restaurants SET theme_primary=?, theme_accent=?, theme_background=?, theme_surface=?, theme_text=?, logo_path=?, hero_path=?, storefront_message=?, vendor_description=? WHERE id=?');
            $stmt->execute([$themePrimary, $themeAccent, $themeBackground, $themeSurface, $themeText, $logoPath, $heroPath, $storefrontMessage ?: null, $vendorDescription ?: null, $vendorId]);

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

            if (!empty($_POST['clear_snapscan'])) {
                delete_vendor_integration($pdo, $vendorId, 'snapscan');
            } else {
                save_vendor_integration($pdo, $vendorId, 'snapscan', ($_POST['snapscan_environment'] ?? '') === 'test' ? 'test' : 'live', !empty($_POST['snapscan_enabled']), [
                    'snap_code' => $_POST['snapscan_snap_code'] ?? '',
                    'api_key' => $_POST['snapscan_api_key'] ?? '',
                ]);
            }

            if (!empty($_POST['clear_zapper'])) {
                delete_vendor_integration($pdo, $vendorId, 'zapper');
            } else {
                save_vendor_integration($pdo, $vendorId, 'zapper', ($_POST['zapper_environment'] ?? '') === 'live' ? 'live' : 'test', !empty($_POST['zapper_enabled']), [
                    'merchant_id' => $_POST['zapper_merchant_id'] ?? '',
                    'site_id' => $_POST['zapper_site_id'] ?? '',
                    'api_key' => $_POST['zapper_api_key'] ?? '',
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
                'snapscan_configured' => integration_for_vendor($pdo, $vendorId, 'snapscan') !== null,
                'zapper_configured' => integration_for_vendor($pdo, $vendorId, 'zapper') !== null,
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

    $vendor = compact('name', 'slug', 'status') + [
        'contact_email' => $contactEmail, 'contact_phone' => $contactPhone,
        'theme_primary' => $themePrimary, 'theme_accent' => $themeAccent,
        'theme_background' => $themeBackground, 'theme_surface' => $themeSurface, 'theme_text' => $themeText,
        'storefront_message' => $storefrontMessage,
        'vendor_description' => $vendorDescription,
    ];
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
<form method="post" enctype="multipart/form-data" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="vendor_id" value="<?= (int) ($vendorId ?: 0) ?>">
<section class="card"><h2>Vendor details</h2><div class="grid">
    <div><label for="name">Business name</label><input id="name" name="name" value="<?= e($vendor['name']) ?>" required></div>
    <div><label for="slug">Vendor slug</label><input id="slug" name="slug" value="<?= e($vendor['slug']) ?>" pattern="[a-z0-9-]+" required></div>
    <div><label for="contact_email">Contact email</label><input id="contact_email" type="email" name="contact_email" value="<?= e($vendor['contact_email']) ?>"></div>
    <div><label for="contact_phone">Contact phone</label><input id="contact_phone" name="contact_phone" value="<?= e($vendor['contact_phone']) ?>"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="active" <?= $vendor['status']==='active'?'selected':'' ?>>Active</option><option value="suspended" <?= $vendor['status']==='suspended'?'selected':'' ?>>Suspended</option></select></div>
</div></section>
<section class="card"><h2>Storefront appearance</h2><p class="muted">A restrained brand theme is applied to the customer menu, cart, checkout, and order status.</p><div class="grid">
    <div><label for="theme_primary">Primary color</label><input id="theme_primary" type="color" name="theme_primary" value="<?= e($vendor['theme_primary']) ?>"></div>
    <div><label for="theme_accent">Accent color</label><input id="theme_accent" type="color" name="theme_accent" value="<?= e($vendor['theme_accent']) ?>"></div>
    <div><label for="theme_background">Page background</label><input id="theme_background" type="color" name="theme_background" value="<?= e($vendor['theme_background']) ?>"></div>
    <div><label for="theme_surface">Card background</label><input id="theme_surface" type="color" name="theme_surface" value="<?= e($vendor['theme_surface']) ?>"></div>
    <div><label for="theme_text">Text color</label><input id="theme_text" type="color" name="theme_text" value="<?= e($vendor['theme_text']) ?>"></div>
    <div class="full"><label for="storefront_message">Storefront message</label><input id="storefront_message" name="storefront_message" maxlength="255" value="<?= e($vendor['storefront_message'] ?? '') ?>"></div>
    <div class="full" data-rich-text>
        <label for="vendor_description">Vendor introduction</label>
        <p class="muted">Shown on the customer storefront and vendor staff portal. Formatting is restricted to safe headings, paragraphs, emphasis, lists, quotes, and links.</p>
        <div class="rich-text-toolbar" data-toolbar hidden aria-label="Formatting controls">
            <button type="button" data-command="bold"><strong>Bold</strong></button>
            <button type="button" data-command="italic"><em>Italic</em></button>
            <button type="button" data-command="formatBlock" data-value="h2">Heading</button>
            <button type="button" data-command="insertUnorderedList">Bullets</button>
            <button type="button" data-command="insertOrderedList">Numbers</button>
            <button type="button" data-command="createLink">Link</button>
            <button type="button" data-command="removeFormat">Clear formatting</button>
        </div>
        <div class="rich-text-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Vendor introduction" hidden></div>
        <textarea id="vendor_description" name="vendor_description" maxlength="20000" rows="10"><?= e($vendor['vendor_description'] ?? '') ?></textarea>
    </div>
    <div><label for="logo">Logo image</label><input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp"><?php if (!empty($vendor['logo_path'])): ?><small>Current logo saved</small><?php endif; ?></div>
    <div><label for="hero">Background/hero image</label><input id="hero" type="file" name="hero" accept="image/png,image/jpeg,image/webp"><?php if (!empty($vendor['hero_path'])): ?><small>Current hero saved</small><?php endif; ?></div>
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
<section class="card"><h2>SnapScan</h2><p class="muted">Saved credentials: <?= e($snapscan['config_hint'] ?? 'Not configured') ?>. Blank fields retain saved values.</p><div class="grid">
    <div><label for="snapscan_environment">Environment</label><select id="snapscan_environment" name="snapscan_environment"><option value="live" <?= ($snapscan['environment'] ?? 'live')==='live'?'selected':'' ?>>Live</option><option value="test" <?= ($snapscan['environment'] ?? '')==='test'?'selected':'' ?>>Test</option></select></div>
    <div><label for="snapscan_snap_code">Merchant SnapCode</label><input id="snapscan_snap_code" name="snapscan_snap_code" autocomplete="off"></div>
    <div><label for="snapscan_api_key">API key</label><input id="snapscan_api_key" type="password" name="snapscan_api_key" autocomplete="new-password"></div>
    <div><label><input type="checkbox" name="snapscan_enabled" value="1" <?= !empty($snapscan['enabled'])?'checked':'' ?>> Enable SnapScan</label></div>
    <?php if ($snapscan): ?><div><label><input type="checkbox" name="clear_snapscan" value="1"> Remove saved SnapScan credentials</label></div><?php endif; ?>
</div></section>
<section class="card"><h2>Zapper</h2><p class="muted">Saved credentials: <?= e($zapper['config_hint'] ?? 'Not configured') ?>. Blank fields retain saved values.</p><div class="grid">
    <div><label for="zapper_environment">Environment</label><select id="zapper_environment" name="zapper_environment"><option value="test" <?= ($zapper['environment'] ?? 'test')==='test'?'selected':'' ?>>Test</option><option value="live" <?= ($zapper['environment'] ?? '')==='live'?'selected':'' ?>>Live</option></select></div>
    <div><label for="zapper_merchant_id">Merchant ID</label><input id="zapper_merchant_id" name="zapper_merchant_id" autocomplete="off"></div>
    <div><label for="zapper_site_id">Site ID</label><input id="zapper_site_id" name="zapper_site_id" autocomplete="off"></div>
    <div><label for="zapper_api_key">API key</label><input id="zapper_api_key" type="password" name="zapper_api_key" autocomplete="new-password"></div>
    <div><label><input type="checkbox" name="zapper_enabled" value="1" <?= !empty($zapper['enabled'])?'checked':'' ?>> Enable Zapper</label></div>
    <?php if ($zapper): ?><div><label><input type="checkbox" name="clear_zapper" value="1"> Remove saved Zapper credentials</label></div><?php endif; ?>
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
<script src="/assets/js/rich-text-editor.js?v=20260902-1" defer></script>
<?php require __DIR__ . '/_footer.php'; ?>
