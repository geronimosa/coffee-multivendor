<?php
require_once 'includes/db.php';
session_start();

$orderId = $_GET['id'] ?? null;
$restaurantId = $_GET['rid'] ?? null;

if (!$orderId || !$restaurantId) {
    die("Missing order or restaurant ID.");
}

// Mark the order as a credit card payment
$stmt = $pdo->prepare("UPDATE orders SET credit_card_payment = 1, payment_method = 'counter_card' WHERE id = ?");
$stmt->execute([$orderId]);

// Optional: Fetch restaurant name for context
$stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pay by Credit Card</title>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .notice-box {
            max-width: 400px;
            margin: 40px auto;
            background: #fff;
            padding: 20px;
            border: 2px solid #A9745B;
            border-radius: 10px;
            text-align: center;
            font-family: sans-serif;
        }
        .notice-box h2 {
            color: #A9745B;
            margin-bottom: 15px;
        }
        .button {
            display: inline-block;
            padding: 12px 20px;
            background: #A9745B;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="notice-box">
        <h2><?= htmlspecialchars($restaurant['name'] ?? 'Coffee Shop') ?></h2>
        <p><strong>Your payment is not confirmed.</strong><br>
        Your order will be made. Please be sure to make payment when you reach the counter.</p>

        <a class="button" href="menu.php?rid=<?= $restaurantId ?>">← Back to Food Menu</a>
    </div>
</body>
</html>
