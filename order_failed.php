<?php
$order_id = $_GET['order'] ?? 'unknown';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Failed</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1>Payment Failed</h1>
    <p>Unfortunately, your payment for order <strong>#<?= htmlspecialchars($order_id) ?></strong> was not successful.</p>
    <a href="checkout.php?order=<?= urlencode($order_id) ?>">Try Again</a>
</body>
</html>