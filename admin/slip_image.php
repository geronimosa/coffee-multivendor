<?php
require_once '../includes/db.php';

$orderId = $_GET['id'] ?? null;
$restaurantId = $_GET['rid'] ?? null;
if (!$orderId || !$restaurantId) die("Missing order ID or restaurant ID");

// Fetch order
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND restaurant_id = ?");
$stmt->execute([$orderId, $restaurantId]);
$order = $stmt->fetch();
if (!$order) die("Order not found");

// Fetch items
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
    <meta charset="utf-8">
    <title>Receipt #<?= $order['id'] ?></title>
    <style>
        body {
            width: 300px;
            font-family: monospace;
            background: #fff;
            color: #000;
            padding: 10px;
            margin: auto;
            border: 1px dashed #ccc;
        }
        h2, h3, .center {
            text-align: center;
            margin: 5px 0;
        }
        .line {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }
        .header, .footer {
            text-align: center;
            font-size: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 3px 0;
        }
        th {
            text-align: left;
            border-bottom: 1px solid #000;
        }
        .total {
            font-weight: bold;
            font-size: 16px;
        }
        .barcode {
            text-align: center;
            font-size: 12px;
            margin-top: 10px;
            letter-spacing: 2px;
        }
    </style>
</head>
<body>
    <h2>**********************</h2>
    <h3>RECEIPT</h3>
    <?php if ($order['credit_card_payment']): ?>
        <div class="center" style="font-weight: bold; font-size: 18px; color: red; margin-bottom: 10px;">
            PAYMENT DUE
        </div>
    <?php endif; ?>
    <h2>**********************</h2>

    <div class="center"><strong>COFFEE SHOP</strong></div>
    <div class="header">
        Address: 123 Bean Street<br>
        Date: <?= date("Y-m-d H:i") ?><br>
        Customer: <?= htmlspecialchars($order['name']) ?>
    </div>

    <div class="line"></div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align:right;">Price</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <?= $item['quantity'] ?>x <?= htmlspecialchars($item['name']) ?>
                    <?= $item['variant_label'] ? '(' . htmlspecialchars($item['variant_label']) . ')' : '' ?>
                </td>
                <td style="text-align:right;">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="line"></div>

    <table>
        <tr>
            <td><strong>Total</strong></td>
            <td style="text-align:right;" class="total">R<?= number_format($order['total'], 2) ?></td>
        </tr>
    </table>

    <div class="line"></div>
    <?php if ($order['credit_card_payment']): ?>
        <div class="center" style="font-weight: bold; font-size: 18px; color: red; margin-bottom: 10px;">
            PAYMENT DUE
        </div>
    <?php endif; ?>

    <div class="center"><strong>THANK YOU</strong></div>
    

    <div class="barcode">
        <?=  $restaurantId . " " . str_pad($order['id'], 5, "0", STR_PAD_LEFT) ?>
    </div>
</body>
</html>