<?php
session_start();
require_once 'includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
$cart = $_SESSION['cart'] ?? [];

if (!$restaurantId ) {
    die("Missing restaurant ");
}
if ( empty($cart)) {
    die("Missing cart.");
}
$logout=$_GET['logout'] ?? null;

$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if (empty($name) || empty($phone)) {
    die("Name and phone number are required.");
}

// Calculate total
$total = 0;
foreach ($cart as $item) {
    $total += $item['unit_price'] * $item['qty'];
}

$token = bin2hex(random_bytes(8));

// Insert order
$stmt = $pdo->prepare("INSERT INTO orders (restaurant_id, name, phone, total, token, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
$stmt->execute([$restaurantId, $name, $phone, $total, $token]);
$order_id = $pdo->lastInsertId();

// Insert order items
$itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, variant_label, quantity, unit_price) VALUES (?, ?, ?, ?, ?)");
foreach ($cart as $item) {
    $itemStmt->execute([
        $order_id,
        $item['id'],
        $item['variant'],
        $item['qty'],
        $item['unit_price']
    ]);
}

// Clear the cart
unset($_SESSION['cart']);

// Fetch restaurant SnapScan info
$stmt = $pdo->prepare("SELECT name, snapscan_api_key, snapscan_code FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
$snapscan_enabled=true;
if (!$restaurant || !$restaurant['snapscan_api_key'] || !$restaurant['snapscan_code']) {
    $snapscan_enabled=false;
    
}

$snapApiKey = $restaurant['snapscan_api_key'];
$snapCode = $restaurant['snapscan_code'];
$returnBase = "https://" . $_SERVER['HTTP_HOST'];
$successUrl = "$returnBase/order_success.php?order=$order_id";
$failUrl = "$returnBase/order_failed.php?order=$order_id";
?>

<!DOCTYPE html>
<html>
<head>
    <title>Confirm Your Order</title>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .till-slip { max-width: 350px; margin: 40px auto; background: white; padding: 16px; border-radius: 10px; border: 1px solid #ccc; }
        .button { display: block; width: 95%; padding: 12px; margin-top: 20px; background: #A9745B; color: white; border: none; border-radius: 5px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; }
        table { width: 100%; margin-top: 10px; }
        td { padding: 6px 0; }
        h3, .center { text-align: center; }
    </style>
</head>
<body>
    <div class="till-slip">
        <h3><?= htmlspecialchars($restaurant['name']) ?></h3>
        <p><strong>Name:</strong> <?= htmlspecialchars($name) ?><br>
           <strong>Phone:</strong> <?= htmlspecialchars($phone) ?></p>

        <table>
            <?php foreach ($_SESSION['last_cart'] = $cart as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['variant']) ?>)</td>
                    <td align="right">R<?= number_format($item['unit_price'] * $item['qty'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="center" style="margin-top: 10px; font-weight: bold;">Total: R<?= number_format($total, 2) ?></div>

        <div class="center" style="margin-top: 20px;">
<?php
if ($snapscan_enabled){
$svgQrUrl = "https://pos.snapscan.io/qr/" . urlencode($snapCode) . ".svg" .
    "?id=$order_id" .
    "&amount=" . ($total * 100) .
    "&statementReference=" . urlencode($name) .
    "&merchantReference=" . urlencode($order_id) .
    "&snap_code_size=155";
?>


    <p>Scan to Pay with SnapScan:</p>
    <img src="<?= $svgQrUrl ?>" alt="SnapScan QR Code" style="width: 100%; max-width: 300px; padding: 10px; border-radius: 8px; background: #fff;" />
</div>
        
        <a class="button" href="https://pos.snapscan.io/qr/<?= urlencode($snapCode) ?>?id=<?= $order_id ?>&amount=<?= $total * 100 ?>&merchantReference=<?= $order_id ?>">
            Click for SnapScan
        </a>
 
        <?php
}
        ?>
        
        
        <a class="button" href="pay_by_card.php?id=<?= $order_id ?>&rid=<?= $restaurantId ?>&merchantReference=<?= $order_id ?>">
            Click to pay at counter by card.
        </a>
    </div>
    <?php if (!empty($logout)): ?>
<div style="display: flex; justify-content: center; align-items: center; height: 20vh;">
  <form method="get" action="<?= htmlspecialchars($logout) ?>">
    <button style="
      padding: 5px 10px;
      font-size: 1.5rem;
      background-color: #333;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
    " type="submit">Logout of hotspot</button>
  </form>
</div>
<?php endif; ?>
</body>
</html>