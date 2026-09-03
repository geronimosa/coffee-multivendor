<?php
require_once '../includes/db.php';
require_once '../includes/phpqrcode/qrlib.php';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$restaurantId = filter_input(INPUT_GET, 'rid', FILTER_VALIDATE_INT);
$expires = filter_input(INPUT_GET, 'expires', FILTER_VALIDATE_INT);
$signature = (string)($_GET['signature'] ?? '');
$signingKey = (string)getenv('APP_KEY');
$expected = ($orderId && $restaurantId && $expires && $signingKey !== '')
    ? hash_hmac('sha256', $orderId . '|' . $restaurantId . '|' . $expires, $signingKey)
    : '';
if (!$orderId || !$restaurantId || !$expires || $expires < time() || $expires > time() + 600 || !hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Invalid or expired receipt link');
}

// Fetch order and vendor details used on the receipt.
$stmt = $pdo->prepare("SELECT o.*, r.name AS vendor_name, r.contact_phone AS vendor_phone
    FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
    WHERE o.id = ? AND o.restaurant_id = ?");
$stmt->execute([$orderId, $restaurantId]);
$order = $stmt->fetch();
if (!$order) die("Order not found");

// Fetch items
$stmt = $pdo->prepare("
    SELECT oi.variant_label, oi.item_note, oi.quantity, oi.unit_price,
           COALESCE(oi.item_name, m.name, 'Menu item') AS name
    FROM order_items oi
    LEFT JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

$orderReference = !empty($order['order_uuid']) ? strtoupper(substr((string)$order['order_uuid'], 0, 8)) : str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
$qrPayload = implode('|', [
    'QRKIOSK RECEIPT',
    'VENDOR:' . $restaurantId,
    'ORDER:' . $orderId,
    'REF:' . $orderReference,
    'DATE:' . (string)$order['created_at'],
    'TOTAL:ZAR ' . number_format((float)$order['total'], 2, '.', ''),
]);
$qrTemp = tempnam(sys_get_temp_dir(), 'qrkiosk_receipt_');
if ($qrTemp === false) {
    http_response_code(500);
    exit('Could not create receipt QR code');
}
QRcode::png($qrPayload, $qrTemp, QR_ECLEVEL_M, 5, 2);
$qrData = base64_encode((string)file_get_contents($qrTemp));
unlink($qrTemp);
$paymentStatus = strtolower((string)($order['payment_status'] ?? 'unpaid'));
$isPaid = $paymentStatus === 'paid';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt #<?= $order['id'] ?></title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #eef1ed; color: #17211c; }
        body { width: 520px; padding: 24px 30px; font-family: Arial, Helvetica, sans-serif; }
        .slip { width: 460px; background: #fff; padding: 30px 32px 26px; border-radius: 12px; box-shadow: 0 8px 24px rgba(23,33,28,.14); }
        .brand { text-align: center; }
        .brand h1 { margin: 0; color: #1f4d3a; font-size: 28px; letter-spacing: -.5px; }
        .brand p { margin: 6px 0 0; color: #617067; font-size: 13px; }
        .ready { margin: 22px 0 16px; padding: 11px; border-radius: 8px; text-align: center; background: #e4f1e9; color: #1f4d3a; font-weight: 700; letter-spacing: 1.2px; }
        .meta { width: 100%; border-collapse: collapse; font-size: 13px; }
        .meta td { padding: 3px 0; }
        .meta td:last-child { text-align: right; font-weight: 700; }
        .rule { border-top: 2px dashed #c9d0cb; margin: 18px 0; }
        .items { width: 100%; border-collapse: collapse; font-size: 14px; }
        .items th { padding: 0 0 8px; color: #617067; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; text-align: left; }
        .items th:last-child, .items td:last-child { text-align: right; white-space: nowrap; }
        .items td { padding: 8px 0; vertical-align: top; border-top: 1px solid #edf0ed; }
        .variant, .note { display: block; margin-top: 3px; color: #69766e; font-size: 12px; }
        .note { color: #8b5b08; font-style: italic; }
        .totals { width: 100%; border-collapse: collapse; }
        .totals td { padding: 4px 0; }
        .totals .grand td { padding-top: 10px; font-size: 22px; font-weight: 800; }
        .totals td:last-child { text-align: right; }
        .payment { margin: 14px 0 20px; padding: 10px; border: 2px solid <?= $isPaid ? '#2f7a52' : '#b7791f' ?>; border-radius: 7px; color: <?= $isPaid ? '#2f7a52' : '#8b5b08' ?>; text-align: center; font-weight: 800; }
        .qr { text-align: center; }
        .qr img { width: 146px; height: 146px; image-rendering: crisp-edges; }
        .qr strong, .qr small { display: block; }
        .qr strong { margin-top: 5px; font-size: 13px; }
        .qr small { margin-top: 4px; color: #7a857e; font-size: 11px; }
        .thanks { margin: 20px 0 0; text-align: center; color: #1f4d3a; font-weight: 700; }
    </style>
</head>
<body><main class="slip">
    <header class="brand">
        <h1><?= htmlspecialchars((string)$order['vendor_name']) ?></h1>
        <p>Digital collection receipt<?= !empty($order['vendor_phone']) ? ' · '.htmlspecialchars((string)$order['vendor_phone']) : '' ?></p>
    </header>
    <div class="ready">ORDER READY FOR COLLECTION</div>
    <table class="meta">
        <tr><td>Order</td><td>#<?= (int)$orderId ?> · <?= htmlspecialchars($orderReference) ?></td></tr>
        <tr><td>Customer</td><td><?= htmlspecialchars((string)$order['name']) ?></td></tr>
        <tr><td>Ordered</td><td><?= htmlspecialchars(date('d M Y, H:i', strtotime((string)$order['created_at']))) ?></td></tr>
    </table>
    <div class="rule"></div>
    <table class="items">
        <thead>
            <tr><th>Items</th><th>Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <strong><?= (int)$item['quantity'] ?> × <?= htmlspecialchars((string)$item['name']) ?></strong>
                    <?php if (!empty($item['variant_label'])): ?><span class="variant"><?= htmlspecialchars((string)$item['variant_label']) ?></span><?php endif; ?>
                    <?php if (!empty($item['item_note'])): ?><span class="note">Note: <?= htmlspecialchars((string)$item['item_note']) ?></span><?php endif; ?>
                </td>
                <td style="text-align:right;">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="rule"></div>
    <table class="totals">
        <tr class="grand"><td>Total</td><td>R<?= number_format((float)$order['total'], 2) ?></td></tr>
    </table>
    <div class="payment"><?= $isPaid ? 'PAID' : 'PAYMENT DUE' ?></div>
    <div class="qr">
        <img src="data:image/png;base64,<?= $qrData ?>" alt="Receipt reference QR code">
        <strong>Scan for receipt reference</strong>
        <small><?= htmlspecialchars($orderReference) ?> · Vendor <?= (int)$restaurantId ?></small>
    </div>
    <p class="thanks">Thank you for supporting us!</p>
</main></body>
</html>
