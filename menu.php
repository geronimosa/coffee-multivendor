<?php
require_once 'includes/db.php';
session_start();

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");
$logout=$_GET['logout'] ?? null;
        

// Fetch restaurant name
$stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();
if (!$restaurant) die("Restaurant not found");

// Fetch menu items
$stmt = $pdo->prepare("SELECT * FROM menu_items WHERE restaurant_id = ?");
$stmt->execute([$restaurantId]);
$items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($restaurant['name']) ?> - Menu</title>
    <style>
        body { font-family: sans-serif; }
        .item { margin-bottom: 20px; padding: 10px; border-bottom: 1px solid #ccc; }
        select, input[type=number] { width: 100%; padding: 5px; margin-top: 5px; }
    </style>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<h2><?= htmlspecialchars($restaurant['name']) ?> - Menu</h2>
<form method="POST" action="cart.php?rid=<?= $restaurantId ?>&logout=<?= $logout ?>">
<table border=1 style="width:100%; border-collapse:collapse;">
    <tr>
        <th>Item</th>
        <th>Variant</th>
        <th>Qty</th>
    </tr>
    <?php foreach ($items as $item): 
        $variants = json_decode($item['variant_options'], true);
        if (!$variants) continue;
    ?>
    <tr style="border-bottom: 1px solid #ddd;">
        <td>
            <strong><?= htmlspecialchars($item['name']) ?></strong><br>
            <small><?= htmlspecialchars($item['category']) ?></small>
        </td>
        <td>
            <select name="items[<?= $item['id'] ?>][variant]" style="width: 100%;">
                <?php foreach ($variants as $v): ?>
                    <option value="<?= htmlspecialchars($v['label']) ?>">
                        <?= htmlspecialchars($v['label']) ?> - R<?= number_format($v['price'], 2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td style="text-align: center;">
            <input type="number" name="items[<?= $item['id'] ?>][qty]" min="0" value="0" style="width: 60px;" onfocus="this.select()">
        </td>
    </tr>
    <?php endforeach; ?>
    <tr>
    <td colspan="2"></td>
    <td style="text-align: center; padding-top: 1rem;">
        <button type="submit" class="button" style="padding: 0.5rem 1rem; font-weight: bold;">Add to Cart</button>
    </td>
    
    
</tr>
</table>

   
        
</form>
<?php if (!empty($_GET['logout'])): ?>
  <form method="get" action="<?= htmlspecialchars($_GET['logout']) ?>">
    <button type="submit">Logout</button>
  </form>
<?php endif; ?>
</body>
</html>