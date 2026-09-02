<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

// Fetch orders
$stmt = $pdo->prepare("
    SELECT o.id, o.status, o.total
    FROM orders o
    WHERE o.restaurant_id = ? AND o.status = 'complete'
    ORDER BY o.id DESC
");
$stmt->execute([$restaurantId]);
$orders = $stmt->fetchAll();

// Preload order items
$orderMap = [];
if ($orders) {
    $orderIds = implode(",", array_column($orders, 'id'));
    $stmt = $pdo->query("
        SELECT oi.order_id, oi.variant_label, oi.quantity, m.name, oi.unit_price
        FROM order_items oi
        JOIN menu_items m ON oi.menu_item_id = m.id
        WHERE oi.order_id IN ($orderIds)
        ORDER BY oi.order_id DESC
    ");
    foreach ($stmt->fetchAll() as $item) {
        $orderMap[$item['order_id']][] = $item;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order Queue - Compact View</title>
    <meta http-equiv="refresh" content="5">
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .order-slip-link {
            text-decoration: none;
            color: inherit;
        }

        .order-slip-link:hover .order-slip {
            outline: 2px solid #A9745B;
            cursor: pointer;
        }

        .order-slip {
            width: 300px;
            padding: 12px;
            margin: 15px;
            background: white;
            border: 1px solid #ccc;
            border-radius: 8px;
            display: inline-block;
            vertical-align: top;
            font-size: 0.9em;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .order-slip h3 {
            margin-top: 0;
            font-size: 1.1em;
        }

        .order-slip table {
            width: 100%;
            border: none;
            margin: 10px 0;
        }

        .order-slip td {
            padding: 4px 0;
        }

        .status-tag {
            display: inline-block;
            padding: 2px 6px;
            background: #DAB894;
            border-radius: 4px;
            font-size: 0.8em;
            color: #2C1B13;
        }
    </style>
</head>
<body>

<h2>Orders - Kitchen View</h2>
<a href="dashboard.php?rid=<?= $restaurantId ?>">⬅ Back to Dashboard</a><br><br>

<?php if (empty($orders)): ?>
    <p>No active orders.</p>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <a href="order_detail.php?rid=<?= $restaurantId ?>&id=<?= $order['id'] ?>" class="order-slip-link">
            <div class="order-slip">
                <h3>#<?= $order['id'] ?> <span class="status-tag"><?= ucfirst($order['status']) ?></span></h3>
                <table>
                    <?php foreach ($orderMap[$order['id']] ?? [] as $item): ?>
                    <tr>
                        <td colspan="2"><strong><?= htmlspecialchars($item['name']) ?></strong> (<?= htmlspecialchars($item['variant_label']) ?>)</td>
                    </tr>
                    <tr>
                        <td>Qty: <?= $item['quantity'] ?></td>
                        <td align="right">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <strong>Total: R<?= number_format($order['total'], 2) ?></strong>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
