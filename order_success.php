<?php
$order_id = $_GET['order'] ?? 'unknown';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1>Thank you!</h1>
    <p>Your payment for order <strong>#<?= htmlspecialchars($order_id) ?></strong> was successful.</p>
    <a href="menu.php?rid=<?= urlencode($_GET['rid'] ?? '') ?>">Return to Menu</a>
</body>
</html>