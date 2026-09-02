<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

$date = $_GET['date'] ?? date('Y-m-d');

// Fetch restaurant name
$stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) die("Restaurant not found");

// Fetch turnover summary
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as order_count,
        SUM(total) as total_revenue
    FROM orders
    WHERE restaurant_id = ?
    AND DATE(created_at) = ?
    AND status IN ('paid', 'preparing', 'complete', 'collected')
");
$stmt->execute([$restaurantId, $date]);
$summary = $stmt->fetch();

// Optional: Status breakdown
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) as count, SUM(total) as subtotal
    FROM orders
    WHERE restaurant_id = ?
    AND DATE(created_at) = ?
    GROUP BY status
");
$stmt->execute([$restaurantId, $date]);
$statusBreakdown = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Daily Turnover - <?= htmlspecialchars($restaurant['name']) ?></title>
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
        .summary {
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>

<h2>Daily Turnover for <?= htmlspecialchars($restaurant['name']) ?></h2>

<form method="GET">
    <input type="hidden" name="rid" value="<?= $restaurantId ?>">
    <label>Select Date:</label>
    <input type="date" name="date" value="<?= $date ?>">
    <button type="submit">View</button>
</form>

<div class="summary">
    <p><strong>Total Orders:</strong> <?= $summary['order_count'] ?? 0 ?></p>
    <p><strong>Total Revenue:</strong> R <?= number_format($summary['total_revenue'] ?? 0, 2) ?></p>
</div>

<?php if ($statusBreakdown): ?>
    <h3>Status Breakdown</h3>
    <table>
        <tr>
            <th>Status</th>
            <th>Orders</th>
            <th>Revenue</th>
        </tr>
        <?php foreach ($statusBreakdown as $row): ?>
            <tr>
                <td><?= ucfirst($row['status']) ?></td>
                <td><?= $row['count'] ?></td>
                <td>R <?= number_format($row['subtotal'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<p style="text-align:center; margin-top: 2rem;">
    <a href="dashboard.php?rid=<?= $restaurantId ?>">⬅ Back to Dashboard</a>
</p>

</body>
</html>