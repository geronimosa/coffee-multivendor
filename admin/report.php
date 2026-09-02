<?php
require_once '../includes/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

$date = $_GET['date'] ?? date('Y-m-d');

// Fetch restaurant name
$stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) die("Restaurant not found");

// Fetch product sales summary
$stmt = $pdo->prepare("
    SELECT 
        m.name AS product_name,
        oi.variant_label,
        SUM(oi.quantity) AS total_sold,
        SUM(oi.subtotal) AS total_revenue
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE o.restaurant_id = ?
      AND DATE(o.created_at) = ?
      AND o.status IN ('paid', 'preparing', 'complete', 'collected')
    GROUP BY m.name, oi.variant_label
    ORDER BY m.name ASC, oi.variant_label ASC
");

$stmt->execute([$restaurantId, $date]);
$products = $stmt->fetchAll();
$totalRevenue = 0;
foreach ($products as $p) {
    $totalRevenue += $p['total_revenue'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Daily Product Sales - <?= htmlspecialchars($restaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <style>
        body {
            font-family: sans-serif;
            background: #f9f9f9;
            padding: 1rem;
            max-width: 600px;
            margin: auto;
        }
        h2 {
            text-align: center;
        }
        form {
            margin-bottom: 1.5rem;
            text-align: center;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f0f0f0;
        }
    </style>
</head>
<body>

<h2>Product Sales for <?= htmlspecialchars($restaurant['name']) ?></h2>

<form method="GET">
    <input type="hidden" name="rid" value="<?= $restaurantId ?>">
    <label>Select Date:</label>
    <input type="date" name="date" value="<?= $date ?>">
    <button type="submit">View</button>
</form>

<?php if ($products): ?>
    <table>
        <tr>
            <th>Product</th>
            <th>Qty Sold</th>
            <th>Revenue (R)</th>
        </tr>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['product_name']) ?></td>
                <td><?= $p['total_sold'] ?></td>
                <td><?= number_format($p['total_revenue'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p style="text-align:center;">No sales data for this date.</p>
<?php endif; ?>
    <p style="text-align: right; font-weight: bold; margin-top: 1rem;">
    Total Revenue: R <?= number_format($totalRevenue, 2) ?>
</p>

<p style="text-align:center; margin-top: 2rem;">
    <a href="dashboard.php?rid=<?= $restaurantId ?>">⬅ Back to Dashboard</a>
</p>

</body>
</html>