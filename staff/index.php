<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vendor_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rich_text.php';
require_once __DIR__ . '/../includes/restaurant_service.php';
require_once __DIR__ . '/../includes/vendor_portal_nav.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['staff_key_login'])) {
    require_csrf();
    if(complete_staff_key_login($pdo,(string)($_POST['staff_key']??''),$vendorId))redirect('/vendor/'.rawurlencode($slug));
    $error='That staff key is invalid, expired or revoked.';
}

$authorized = staff_can_access($pdo, $vendorId);
$isRestaurant = ($vendor['service_model'] ?? 'kiosk') === 'restaurant';
$tabs = [
    'pending' => ['label' => 'Pending', 'where' => "o.status IN ('pending','paid')"],
    'preparing' => ['label' => 'Preparing', 'where' => "o.status='preparing'"],
    'ready' => ['label' => 'Ready', 'where' => "o.status='complete'"],
    'collected' => ['label' => $isRestaurant ? 'Served' : 'Collected', 'where' => "o.status='collected'"],
    'archived' => ['label' => 'Archived', 'where' => "o.status IN ('archived','cancelled')"],
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'pending';

if ($authorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    require_csrf();
    $orderId = (int) $_POST['order_id'];
    $target = (string) ($_POST['status'] ?? '');
    $stmt = $pdo->prepare('SELECT status,credit_card_payment,payment_status,service_type,table_tab_id FROM orders WHERE id=? AND restaurant_id=? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $stmt->execute([$orderId, $vendorId]);
        $order = $stmt->fetch();
        $current = $order['status'] ?? '';
        $isPaid = ($order['payment_status'] ?? 'unpaid') === 'paid';
        $isCounter = !empty($order['credit_card_payment']);
        $isTableOrder = ($order['service_type'] ?? 'kiosk') === 'table';
        $paidPending = $isPaid && in_array($current, ['pending','paid'], true);
        if ($target === 'confirm_payment' && !$isPaid && $isCounter && in_array($current, ['pending','preparing','complete'], true)) {
            $pdo->prepare("UPDATE orders SET payment_status='paid',payment_method='counter_card',paid_at=NOW() WHERE id=? AND restaurant_id=?")->execute([$orderId,$vendorId]);
            $valid = false;
        } else {
        $valid = ($target === 'preparing' && ($paidPending || ($current === 'pending' && ($isCounter || $isTableOrder))))
            || ($target === 'complete' && $current === 'preparing')
            || ($target === 'collected' && $current === 'complete' && ($isPaid || $isTableOrder))
            || ($target === 'archived' && in_array($current, ['collected', 'cancelled'], true))
            || ($target === 'cancelled' && ($paidPending || $isCounter || in_array($current, ['preparing', 'complete'], true)));
        }
        if ($valid) $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND restaurant_id=?')->execute([$target, $orderId, $vendorId]);
        if(!empty($order['table_tab_id']))refresh_table_tab_totals($pdo,(int)$order['table_tab_id']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    redirect('/vendor/' . rawurlencode($slug) . '?tab=' . urlencode($activeTab));
}

$counts = array_fill_keys(array_keys($tabs), 0);
$orders = [];
$orderItems = [];
if ($authorized) {
    foreach ($tabs as $key => $tab) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE o.restaurant_id=? AND ' . $tab['where']);
        $stmt->execute([$vendorId]);
        $counts[$key] = (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT o.*,dt.name table_name,tg.display_name guest_name,GROUP_CONCAT(CONCAT(oi.quantity,'× ',COALESCE(oi.item_name,m.name,'Item'),IF(oi.variant_label IS NULL OR oi.variant_label='','',CONCAT(' (',oi.variant_label,')'))) ORDER BY oi.id SEPARATOR ', ') items FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id LEFT JOIN menu_items m ON m.id=oi.menu_item_id LEFT JOIN table_tabs tt ON tt.id=o.table_tab_id LEFT JOIN dining_tables dt ON dt.id=tt.dining_table_id LEFT JOIN tab_guests tg ON tg.id=o.tab_guest_id WHERE o.restaurant_id=? AND {$tabs[$activeTab]['where']} GROUP BY o.id,dt.name,tg.display_name ORDER BY o.created_at");
    $stmt->execute([$vendorId]);
    $orders = $stmt->fetchAll();
    if ($orders) {
        $placeholders = implode(',', array_fill(0, count($orders), '?'));
        $stmt = $pdo->prepare("SELECT oi.order_id,oi.variant_label,oi.item_note,oi.quantity,oi.unit_price,COALESCE(oi.item_name,m.name,'Item') name FROM order_items oi LEFT JOIN menu_items m ON m.id=oi.menu_item_id WHERE oi.order_id IN ($placeholders) ORDER BY oi.order_id,oi.id");
        $stmt->execute(array_column($orders, 'id'));
        foreach ($stmt->fetchAll() as $line) $orderItems[(int) $line['order_id']][] = $line;
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($vendor['name']) ?> staff</title><link rel="stylesheet" href="/assets/css/super-admin.css?v=20260902-2"><?php if ($authorized): ?><meta http-equiv="refresh" content="15"><?php endif; ?></head><body class="staff-page <?= $authorized ? 'staff-queue' : '' ?>">
<header class="topbar"><strong><?= e($vendor['name']) ?> · Staff</strong><?php if($authorized)vendor_portal_nav($pdo,$vendor,'orders');?><?php if ($authorized): ?><a href="/staff/logout.php?slug=<?= urlencode($slug) ?>">Log out</a><?php endif; ?></header><main class="container">
<?php if (!$authorized): ?><section class="card" style="max-width:480px;margin:auto"><h1>Staff sign in</h1><p class="muted">Enter the 10-character staff key generated for this vendor.</p><?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="staff_key_login" value="1"><label for="staff_key">Staff key</label><input id="staff_key" name="staff_key" required minlength="10" maxlength="10" pattern="[A-HJ-NP-Za-km-z2-9]{10}" autocomplete="one-time-code" autocapitalize="none" spellcheck="false"><p><button type="submit">Open staff portal</button></p></form></section>
<?php else: ?><div class="actions fulfilment-head"><div><h1><?=$isRestaurant?'Kitchen queue':'Fulfilment queue'?></h1><p class="muted">Refreshes every 15 seconds</p></div></div>
<nav class="queue-tabs" aria-label="Order queues"><?php foreach ($tabs as $key => $tab): ?><a class="queue-tab <?= $activeTab === $key ? 'active' : '' ?>" href="?tab=<?= e($key) ?>"><?= e($tab['label']) ?><span><?= $counts[$key] ?></span></a><?php endforeach; ?></nav>
<?php if (!$orders): ?><section class="card empty"><h2>No <?= e(strtolower($tabs[$activeTab]['label'])) ?> orders</h2></section><?php endif; ?>
<?php foreach ($orders as $order):
    $isPaid=$order['payment_status']==='paid';$isTableOrder=($order['service_type']??'kiosk')==='table';$isCounter=!$isPaid&&!empty($order['credit_card_payment']);
    $paymentClass=$isPaid?'paid':(($isCounter||$isTableOrder)?'counter':'unpaid');$paymentLabel=$isPaid?'PAID':($isTableOrder?'OPEN TABLE':($isCounter?'PAY AT COUNTER':'NO PAYMENT'));$receiptId='receipt-'.(int)$order['id'];
?><section class="card order-card receipt-trigger" tabindex="0" role="button" aria-label="View till slip for order <?= (int)$order['id'] ?>" data-receipt-open="<?=e($receiptId)?>">
<div class="actions order-heading"><div><span class="badge"><?=e($tabs[$activeTab]['label'])?></span> <span class="payment-badge <?=$paymentClass?>"><?=$paymentLabel?></span><h2><?php if($isTableOrder):?><?=e($order['table_name'])?> · <?=e($order['guest_name']?:$order['name'])?> · Round <?=(int)$order['round_number']?><?php else:?>Order #<?=(int)$order['id']?> · <?=e($order['name'])?><?php endif;?></h2></div><strong class="order-total">R<?=number_format((float)$order['total'],2)?></strong></div>
<p class="order-items"><?=e($order['items']?:'No items')?></p><span class="receipt-hint">Tap order for full till slip</span>
<form method="post" class="actions order-actions"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$order['id']?>"><input type="hidden" name="tab" value="<?=e($activeTab)?>">
<?php if($activeTab==='pending'&&($isPaid||$isCounter||$isTableOrder)):?><button name="status" value="preparing">Start preparing</button><?php if($isCounter):?><button name="status" value="confirm_payment">Confirm paid</button><?php endif;?><button name="status" value="cancelled">Cancel</button>
<?php elseif($activeTab==='pending'):?><span class="muted">Waiting for online payment</span>
<?php elseif($activeTab==='preparing'):?><button name="status" value="complete">Mark ready</button><?php if($isCounter):?><button name="status" value="confirm_payment">Confirm paid</button><?php endif;?><button name="status" value="cancelled">Cancel</button>
<?php elseif($activeTab==='ready'&&($isPaid||$isTableOrder)):?><button name="status" value="collected">Mark <?=$isTableOrder?'served':'collected'?></button><button name="status" value="cancelled">Cancel</button>
<?php elseif($activeTab==='ready'&&$isCounter):?><button name="status" value="confirm_payment">Confirm payment received</button><span class="muted">Payment required before collection</span>
<?php elseif($activeTab==='ready'):?><span class="muted">Payment required before collection</span>
<?php elseif($activeTab==='collected'):?><button name="status" value="archived">Archive</button><?php elseif($order['status']==='cancelled'):?><button name="status" value="archived">Archive</button><?php endif;?></form></section>
<dialog class="receipt-dialog" id="<?=e($receiptId)?>"><div class="receipt-paper"><button class="receipt-close" type="button" data-receipt-close aria-label="Close till slip">×</button><header><strong><?=e($vendor['name'])?></strong><span><?=$isTableOrder?e($order['table_name']).' · ROUND '.(int)$order['round_number']:'ORDER #'.(int)$order['id']?></span><small><?=e((string)$order['created_at'])?></small></header><div class="receipt-rule"></div><p class="receipt-customer"><?=e($order['name'])?><?php if(!empty($order['phone'])):?> · <?=e($order['phone'])?><?php endif;?></p><ul class="receipt-lines"><?php foreach($orderItems[(int)$order['id']]??[] as $line):$lineTotal=(int)$line['quantity']*(float)$line['unit_price'];?><li><div><strong><?=(int)$line['quantity']?> × <?=e($line['name'])?></strong><?php if($line['variant_label']):?><span><?=e($line['variant_label'])?></span><?php endif;?><?php if(!empty($line['item_note'])):?><span class="receipt-note">Special instruction: <?=e($line['item_note'])?></span><?php endif;?></div><strong>R<?=number_format($lineTotal,2)?></strong></li><?php endforeach;?></ul><div class="receipt-rule"></div><div class="receipt-total"><span>Total</span><strong>R<?=number_format((float)$order['total'],2)?></strong></div><footer><span class="payment-badge <?=$paymentClass?>"><?=$paymentLabel?></span><small>Aim to please.</small></footer></div></dialog><?php endforeach;?>
<?php endif; ?>
<?php if($vendorDescription!==''):?><footer class="card vendor-footer vendor-introduction" aria-label="About <?= e($vendor['name']) ?>"><?= $vendorDescription ?></footer><?php endif;?></main><script src="/assets/js/fulfilment-receipt.js"></script></body></html>
