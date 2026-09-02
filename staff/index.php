<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vendor_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rich_text.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND status='active'");
$stmt->execute([$slug]);
$vendor = $stmt->fetch();
if (!$vendor) { http_response_code(404); exit('Vendor not found.'); }
$vendorId = (int) $vendor['id'];
$vendorDescription = sanitize_vendor_description($vendor['vendor_description'] ?? '');
$message = $error = null;

if (!empty($_GET['token'])) {
    if (complete_staff_token_login($pdo, (string) $_GET['token'], $vendorId)) redirect('/vendor/' . rawurlencode($slug));
    $error = 'This login link is invalid or expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_login'])) {
    require_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $stmt = $pdo->prepare('SELECT u.id,u.name FROM users u JOIN restaurant_users ru ON ru.user_id=u.id WHERE u.email=? AND u.active=1 AND ru.restaurant_id=? LIMIT 1');
    $stmt->execute([$email, $vendorId]);
    $user = $stmt->fetch();
    if ($user) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO login_tokens(user_id,token,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))')->execute([$user['id'], $token]);
        $url = rtrim((string) env('APP_URL'), '/') . '/vendor/' . rawurlencode($slug) . '?token=' . urlencode($token);
        sendMail($email, $user['name'] ?: 'Staff member', $vendor['name'] . ' staff login', '<p>Your secure staff login link:</p><p><a href="' . e($url) . '">Open staff portal</a></p><p>This link expires in 10 minutes.</p>');
    }
    $message = 'If the email belongs to this vendor, a login link has been sent.';
}

$authorized = staff_can_access($pdo, $vendorId);
$tabs = [
    'pending' => ['label' => 'Pending', 'where' => "o.status IN ('pending','paid')"],
    'preparing' => ['label' => 'Preparing', 'where' => "o.status='preparing'"],
    'ready' => ['label' => 'Ready', 'where' => "o.status='complete'"],
    'collected' => ['label' => 'Collected', 'where' => "o.status='collected'"],
    'archived' => ['label' => 'Archived', 'where' => "o.status IN ('archived','cancelled')"],
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'pending';

if ($authorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    require_csrf();
    $orderId = (int) $_POST['order_id'];
    $target = (string) ($_POST['status'] ?? '');
    $stmt = $pdo->prepare('SELECT status,credit_card_payment,payment_status FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $stmt->execute([$orderId, $vendorId]);
        $order = $stmt->fetch();
        $current = $order['status'] ?? '';
        $isPaid = ($order['payment_status'] ?? 'unpaid') === 'paid';
        $isCounter = !empty($order['credit_card_payment']);
        $paidPending = $isPaid && in_array($current, ['pending','paid'], true);
        if ($target === 'confirm_payment' && !$isPaid && $isCounter && in_array($current, ['pending','preparing','complete'], true)) {
            $pdo->prepare("UPDATE orders SET payment_status='paid',payment_method='counter_card',paid_at=NOW() WHERE id=? AND restaurant_id=?")->execute([$orderId,$vendorId]);
            $valid = false;
        } else {
        $valid = ($target === 'preparing' && ($paidPending || ($current === 'pending' && $isCounter)))
            || ($target === 'complete' && $current === 'preparing')
            || ($target === 'collected' && $current === 'complete' && $isPaid)
            || ($target === 'archived' && in_array($current, ['collected', 'cancelled'], true))
            || ($target === 'cancelled' && ($paidPending || $isCounter || in_array($current, ['preparing', 'complete'], true)));
        }
        if ($valid) $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND restaurant_id=?')->execute([$target, $orderId, $vendorId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    redirect('/vendor/' . rawurlencode($slug) . '?tab=' . urlencode($activeTab));
}

$counts = array_fill_keys(array_keys($tabs), 0);
$orders = [];
if ($authorized) {
    foreach ($tabs as $key => $tab) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE o.restaurant_id=? AND ' . $tab['where']);
        $stmt->execute([$vendorId]);
        $counts[$key] = (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT o.*,GROUP_CONCAT(CONCAT(oi.quantity,'× ',m.name,IF(oi.variant_label IS NULL OR oi.variant_label='','',CONCAT(' (',oi.variant_label,')'))) ORDER BY oi.id SEPARATOR ', ') items FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id LEFT JOIN menu_items m ON m.id=oi.menu_item_id WHERE o.restaurant_id=? AND {$tabs[$activeTab]['where']} GROUP BY o.id ORDER BY o.created_at");
    $stmt->execute([$vendorId]);
    $orders = $stmt->fetchAll();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($vendor['name']) ?> staff</title><link rel="stylesheet" href="/assets/css/super-admin.css?v=20260902-1"><?php if ($authorized): ?><meta http-equiv="refresh" content="15"><?php endif; ?></head><body class="<?= $authorized ? 'staff-queue' : '' ?>">
<header class="topbar"><strong><?= e($vendor['name']) ?> · Staff</strong><?php if ($authorized): ?><a href="/staff/logout.php?slug=<?= urlencode($slug) ?>">Log out</a><?php endif; ?></header><main class="container">
<?php if (!$authorized): ?><section class="card" style="max-width:480px;margin:auto"><h1>Staff sign in</h1><?php if($vendorDescription!==''):?><div class="vendor-introduction"><?= $vendorDescription ?></div><?php endif;?><p class="muted">Use the email address assigned to this vendor.</p><?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_login" value="1"><label for="email">Email</label><input id="email" type="email" name="email" required><p><button type="submit">Email login link</button></p></form></section>
<?php else: ?><?php if($vendorDescription!==''):?><section class="card vendor-introduction"><?= $vendorDescription ?></section><?php endif;?><div class="actions fulfilment-head"><div><h1>Fulfilment queue</h1><p class="muted">Refreshes every 15 seconds</p></div><a class="button secondary" href="/shop/<?= e($slug) ?>" target="_blank">Customer shop</a></div>
<nav class="queue-tabs" aria-label="Order queues"><?php foreach ($tabs as $key => $tab): ?><a class="queue-tab <?= $activeTab === $key ? 'active' : '' ?>" href="?tab=<?= e($key) ?>"><?= e($tab['label']) ?><span><?= $counts[$key] ?></span></a><?php endforeach; ?></nav>
<?php if (!$orders): ?><section class="card empty"><h2>No <?= e(strtolower($tabs[$activeTab]['label'])) ?> orders</h2></section><?php endif; ?>
<?php foreach ($orders as $order): $isPaid=$order['payment_status']==='paid';$isCounter=!$isPaid&&!empty($order['credit_card_payment']);$paymentClass=$isPaid?'paid':($isCounter?'counter':'unpaid');$paymentLabel=$isPaid?'PAID':($isCounter?'PAY AT COUNTER':'NO PAYMENT'); ?><section class="card order-card"><div class="actions order-heading"><div><span class="badge"><?= e($tabs[$activeTab]['label']) ?></span> <span class="payment-badge <?= $paymentClass ?>"><?= $paymentLabel ?></span><h2>Order #<?= (int) $order['id'] ?> · <?= e($order['name']) ?></h2></div><strong class="order-total">R<?= number_format((float) $order['total'], 2) ?></strong></div><p class="order-items"><?= e($order['items'] ?: 'No items') ?></p><form method="post" class="actions order-actions"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="tab" value="<?= e($activeTab) ?>"><?php if ($activeTab === 'pending' && ($isPaid || $isCounter)): ?><button name="status" value="preparing">Start preparing</button><?php if ($isCounter): ?><button name="status" value="confirm_payment">Confirm paid</button><?php endif; ?><button name="status" value="cancelled">Cancel</button><?php elseif ($activeTab === 'pending'): ?><span class="muted">Waiting for online payment</span><?php elseif ($activeTab === 'preparing'): ?><button name="status" value="complete">Mark ready</button><?php if ($isCounter): ?><button name="status" value="confirm_payment">Confirm paid</button><?php endif; ?><button name="status" value="cancelled">Cancel</button><?php elseif ($activeTab === 'ready' && $isPaid): ?><button name="status" value="collected">Mark collected</button><button name="status" value="cancelled">Cancel</button><?php elseif ($activeTab === 'ready' && $isCounter): ?><button name="status" value="confirm_payment">Confirm payment received</button><span class="muted">Payment required before collection</span><?php elseif ($activeTab === 'ready'): ?><span class="muted">Payment required before collection</span><?php elseif ($activeTab === 'collected'): ?><button name="status" value="archived">Archive</button><?php elseif ($order['status'] === 'cancelled'): ?><button name="status" value="archived">Archive</button><?php endif; ?></form></section><?php endforeach; ?>
<?php endif; ?></main></body></html>
