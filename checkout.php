<?php
require_once 'includes/db.php';

$order_id = $_GET['order'] ?? null;

if (!$order_id) {
    die("Missing order ID.");
}

// Fetch the order token and restaurant ID
$stmt = $pdo->prepare("SELECT token, restaurant_id FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found.");
}

$token = $order['token'];
$rid = $order['restaurant_id'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .center {
            text-align: center;
            margin-top: 40px;
        }
        .button {
            background-color: #6F4E37;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            text-decoration: none;
        }
        .button:hover {
            background-color: #A9745B;
        }
    </style>
</head>
<body>
    <h1>Thank you!</h1>
    <p>Your payment for order <strong>#<?= htmlspecialchars($order_id) ?></strong> was successful.</p>

    <div class="center">
        <p>Track your order below:</p>
        <a class="button" href="order_status.php?token=<?= urlencode($token) ?>&rid=<?= urlencode($rid) ?>">View Order Status</a>
    </div>
</body>
</html>