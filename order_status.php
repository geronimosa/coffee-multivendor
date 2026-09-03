<?php
require_once 'includes/db.php';

$token = $_GET['token'] ?? null;
if (!$token) die("Missing token");

// Fetch order by token
$stmt = $pdo->prepare("
    SELECT o.id,o.status,o.total,o.name,o.phone,o.service_type,o.round_number,r.slug,dt.name table_name,dt.qr_token table_token,tt.total tab_total
    FROM orders o
    JOIN restaurants r ON r.id=o.restaurant_id
    LEFT JOIN table_tabs tt ON tt.id=o.table_tab_id
    LEFT JOIN dining_tables dt ON dt.id=tt.dining_table_id
    WHERE o.token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$order = $stmt->fetch();

if (!$order) die("Order not found");

// Fetch order items
$stmt = $pdo->prepare("
    SELECT oi.variant_label,oi.item_note,oi.quantity,oi.unit_price,COALESCE(oi.item_name,m.name,'Item') AS name
    FROM order_items oi
    LEFT JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE oi.order_id = ?
");
$stmt->execute([$order['id']]);
$items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Your Order Status</title>
    <meta http-equiv="refresh" content="10">
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        body { font-family: monospace; padding: 20px; background: #f8f8f8; text-align: center; }
        .slip {
            background: white;
            padding: 20px;
            display: inline-block;
            border: 1px solid #ccc;
            border-radius: 10px;
            text-align: left;
            width: 320px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h3 { margin-top: 0; font-size: 1.2em; text-align: center; }
        .status {
            display: inline-block;
            padding: 5px 10px;
            background: #DAB894;
            color: #2C1B13;
            border-radius: 5px;
            font-size: 0.9em;
            margin-bottom: 10px;
        }
        table { width: 100%; font-size: 0.95em; margin-top: 10px; }
        td { padding: 4px 0; }
        .footer { text-align: center; font-size: 0.85em; color: #999; margin-top: 15px; }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<div class="slip">
    <h3>Order #<?= $order['id'] ?></h3>
    <?php if($order['service_type']==='table'):?><p><strong><?=htmlspecialchars($order['table_name'])?></strong> · Round <?= (int)$order['round_number']?></p><?php endif;?>
    <div class="status">Status: <?= ucfirst($order['status']) ?></div>

    <p><strong>Name:</strong><strong><?= htmlspecialchars($order['name']) ?></strong> <?= htmlspecialchars($order['phone']) ?></p>

    <table>
        <?php foreach ($items as $item): ?>
        <tr>
            <td colspan="2"><strong><?= htmlspecialchars($item['name']) ?></strong> (<?= htmlspecialchars($item['variant_label']) ?>)</td>
        </tr>
        <?php if(!empty($item['item_note'])):?><tr><td colspan="2"><em>Special instruction: <?=htmlspecialchars($item['item_note'])?></em></td></tr><?php endif;?>
        <tr>
            <td>Qty: <?= $item['quantity'] ?></td>
            <td align="right">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <p><strong>Total:</strong> R<?= number_format($order['total'], 2) ?></p>
    <?php if($order['service_type']==='table'):?><p><strong>Open table bill:</strong> R<?=number_format((float)$order['tab_total'],2)?></p><p><a href="/shop/<?=htmlspecialchars($order['slug'])?>/table/<?=htmlspecialchars($order['table_token'])?>">Add another round</a></p><?php endif;?>

    <div class="footer">This page refreshes every 10 seconds</div>
</div>
    
<?php
$rid = $_GET['rid'] ?? $_POST['rid'] ?? null;
if ($rid):
?>
<a href="menu.php?rid=<?= urlencode($rid) ?>" class="back-to-menu">← Back to Menu</a>
<?php endif; ?>

</body>
</html>
