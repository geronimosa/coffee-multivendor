<?php
require_once '../includes/db.php';
require_once '../includes/whatsapp.php';

$orderId = $_GET['id'] ?? null;
$restaurantId = $_GET['rid'] ?? null;
if (!$orderId || !$restaurantId) die("Missing order ID or restaurant ID");

// Update status if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    $status = $_POST['status'];

    if ($orderId && $restaurantId) {
        $stmt = $pdo->prepare("
            SELECT id, status, total, name, phone, created_at
            FROM orders
            WHERE id = ? AND restaurant_id = ?
        ");
        $stmt->execute([$orderId, $restaurantId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            

            if ($status === 'complete') {
                
                        $stmt = $pdo->prepare("
                SELECT oi.variant_label, oi.quantity, oi.unit_price, m.name
                FROM order_items oi
                JOIN menu_items m ON oi.menu_item_id = m.id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll();
                // Send WhatsApp notification
                $image=generateSlipImage($orderId, $restaurantId);
               
                sendOrderReadyTemplate($order['phone'], $order['name'], $orderId, $items, $order['total']);
            }

            // Update the order status
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$status, $orderId, $restaurantId]);

            header("Location: orders.php?rid=$restaurantId");
            exit;
        }
    }
}


// Fetch order
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
$stmt->execute([$orderId, $restaurantId]);
$order = $stmt->fetch();
if (!$order) die("Order not found");

// Fetch order items
$stmt = $pdo->prepare("
    SELECT oi.variant_label, oi.quantity, oi.unit_price, m.name
    FROM order_items oi
    JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Order #<?= $order['id'] ?></title>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .slip {
            width: 320px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            font-family: monospace;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        h3 {
            margin-top: 0;
            font-size: 1.2em;
            text-align: center;
        }

        table {
            width: 100%;
            margin: 10px 0;
        }

        td {
            padding: 4px 0;
        }

.button-bar {
    display: flex;
    justify-content: center;
    flex-wrap: nowrap;
    gap: 10px;
    margin-top: 15px;
    overflow-x: auto;
}

.button-bar form {
    flex: 0 0 auto;
}
        .status-btn {
            padding: 8px 12px;
            border: none;
            background: #A9745B;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }

        .status-btn:hover {
            background: #8B5E3C;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
        }

        a {
            color: #333;
            text-decoration: none;
        }
    </style><!-- comment -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<div class="slip">
    <h3>Order #<?= $order['id'] ?> - <?= ucfirst($order['status']) ?></h3>
    <p><strong><?= htmlspecialchars($order['name']) ?></strong><br><?= htmlspecialchars($order['phone']) ?></p>

    <table>
        <?php foreach ($items as $item): ?>
        <tr>
            <td colspan="2"><strong><?= htmlspecialchars($item['name']) ?></strong> (<?= htmlspecialchars($item['variant_label']) ?>)</td>
        </tr>
        <tr>
            <td>Qty: <?= $item['quantity'] ?></td>
            <td align="right">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <p><strong>Total: R<?= number_format($order['total'], 2) ?></strong></p>

    <div class="button-bar">
        <?php foreach (['paid', 'preparing', 'complete', 'collected'] as $status): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="status" value="<?= $status ?>">
                <button class="status-btn"><?= ucfirst($status) ?></button>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="footer">
        <br><a href="orders.php?rid=<?= $restaurantId ?>">⬅ Back to Orders</a>
    </div>
</div>

</body>
</html>