<?php
session_start();
require_once 'includes/db.php';
$logout=$_GET['logout'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items'])) {
    foreach ($_POST['items'] as $itemId => $details) {
        $qty = (int)$details['qty'];
        $variantLabel = $details['variant'];

        if ($qty <= 0) continue;

        // Fetch menu item from DB
        $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) continue;

        $variants = json_decode($item['variant_options'], true);
        $variantPrice = null;

        foreach ($variants as $v) {
            if ($v['label'] === $variantLabel) {
                $variantPrice = $v['price'];
                break;
            }
        }

        if ($variantPrice === null) continue;

        $key = $itemId . ':' . $variantLabel;
        $entry = [
            'id' => $itemId,
            'name' => $item['name'],
            'variant' => $variantLabel,
            'unit_price' => (float)$variantPrice,
            'qty' => $qty
        ];

        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] += $qty;
        } else {
            $_SESSION['cart'][$key] = $entry;
        }
    }
}

// Set or get restaurant ID
$restaurantId = $_GET['rid'] ?? ($_SESSION['restaurant_id'] ?? null);
if ($restaurantId) $_SESSION['restaurant_id'] = $restaurantId;
$cart = $_SESSION['cart'] ?? [];

// Get restaurant info
$restaurant = null;
if ($restaurantId) {
    $stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
    $stmt->execute([$restaurantId]);
    $restaurant = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Your Cart</title>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .till-slip {
            width: 320px;
            margin: 50px auto;
            background: white;
            border: 1px solid #ccc;
            border-radius: 10px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-size: 0.95em;
        }
        h3 { text-align: center; margin-top: 0; }
        table { width: 100%; margin-top: 10px; border-collapse: collapse; }
        td { padding: 6px 0; }
        .total-line {
            border-top: 1px dashed #aaa;
            margin-top: 10px;
            padding-top: 10px;
            font-weight: bold;
            text-align: center;
        }
        .center { text-align: center; }
        .button {
            display: block;
            margin: 20px auto;
            padding: 10px 20px;
            background: #A9745B;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        input[type="text"], input[type="tel"] {
            width: 90%;
            padding: 6px;
            margin: 6px auto;
            display: block;
            text-align: center;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<div class="till-slip">
    <h3><?= htmlspecialchars($restaurant['name'] ?? 'Your Cart') ?></h3>

    <?php if (empty($cart)): ?>
        <p class="center">Your cart is empty.</p>
    <?php else: ?>
        <table>
            <?php $total = 0; ?>
            <?php foreach ($cart as $item): 
                $line = $item['unit_price'] * $item['qty'];
                $total += $line;
            ?>
            <tr>
                <td colspan="2">
                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                    (<?= htmlspecialchars($item['variant']) ?>)
                </td>
            </tr>
            <tr>
                <td>Qty: <?= $item['qty'] ?></td>
                <td align="right">R<?= number_format($line, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="total-line">Total: R<?= number_format($total, 2) ?></div>

        <form method="POST" action="confirm_order.php?rid=<?= $restaurantId ?>&logout=<?= $logout ?>">
            <input type="text" name="name" placeholder="Your Name" required>
            <input type="tel" name="phone" placeholder="Phone Number" required>
            <button type="submit" class="button">Place Order</button>
        </form>
    <?php endif; ?>
</div>
<?php
$rid = $_GET['rid'] ?? $_POST['rid'] ?? null;
if ($rid):
?>
<a href="menu.php?rid=<?= urlencode($rid) ?>" class="back-to-menu">← Back to Menu</a>
<?php endif; ?>
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