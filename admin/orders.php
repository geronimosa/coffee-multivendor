<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
$tab = $_GET['tab'] ?? 'active';

if (!$restaurantId) die("Missing restaurant ID");

$action= $_GET['action'] ?? null;
$actiontab= $_GET['tab'] ?? null;
if ($action=='archive'){
    $orderId= $_GET['id'] ?? null;
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND restaurant_id = ?");
    $stmt->execute(['archived', $orderId, $restaurantId]);
    header("Location: orders.php?rid=$restaurantId&tab=$actiontab");
    exit;
}



switch ($tab) {
    case 'completed':
        $statusFilter = "= 'complete'";
        $tabTitle = 'Completed Orders';        
        break;
    case 'collected':
        $statusFilter = "= 'collected'";
        $tabTitle = 'Collected Orders';
        break;
    case 'archived':
        $statusFilter = "= 'archived'";
        $tabTitle = 'Archived Orders';
        break;
    case 'pending':
        $statusFilter = "= 'pending'";
        $tabTitle = 'Pending Orders';
        break;
    default:
        $statusFilter = "NOT IN ('complete', 'collected' , 'archived')";
        $tabTitle = 'Active Orders';
        $tab = 'active';
}

$stmt = $pdo->prepare("
    SELECT o.id, o.status, o.total, o.name, o.phone, o.created_at, o.credit_card_payment
    FROM orders o
    WHERE o.restaurant_id = ? AND o.status $statusFilter
    ORDER BY o.created_at ASC
");
$stmt->execute([$restaurantId]);
$orders = $stmt->fetchAll();

$orderMap = [];
if ($orders) {
    $orderIds = implode(",", array_column($orders, 'id'));
    $stmt = $pdo->query("
        SELECT oi.order_id, oi.variant_label, oi.quantity, m.name, oi.unit_price
        FROM order_items oi
        JOIN menu_items m ON oi.menu_item_id = m.id
        WHERE oi.order_id IN ($orderIds)
        ORDER BY oi.order_id DESC
    ");
    foreach ($stmt->fetchAll() as $item) {
        $orderMap[$item['order_id']][] = $item;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Orders - <?= $tabTitle ?></title>
    <meta http-equiv="refresh" content="5">
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
    <style>
        .tabs { margin-bottom: 20px; }
        .tab-link {
            padding: 10px 20px;
            background: #eee;
            display: inline-block;
            border-radius: 6px 6px 0 0;
            margin-right: 10px;
            text-decoration: none;
            color: #333;
        }
        .tab-link.active { background: #DAB894; font-weight: bold; }
        .order-slip-link {
            text-decoration:none;
            color: inherit;
        }
        .order-slip-link:hover {
            text-decoration: none;
            cursor: pointer;
            
        }
        .order-slip:hover {
            text-decoration: none;
            cursor: pointer;
            background: white;
            border: 1px solid red;
            box-shadow: 0 2px 6px Red;
        }
        .order-slip {
            width: 300px;
            padding: 12px;
            margin: 15px;
            background: white;
            border: 1px solid #ccc;
            border-radius: 8px;
            display: inline-block;
            vertical-align: top;
            font-size: 0.9em;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .order-slip h3 { margin-top: 0; font-size: 1.1em; }
        .order-slip table {
            width: 100%;
            border: none;
            margin: 10px 0;
        }
        .order-slip td { padding: 4px 0; }
        .status-tag {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8em;
            color: #fff;
        }
        .button {
            background-color: #6F4E37;
            color: white;
            border: none;
            padding: 4px 5px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            white-space: nowrap;
        }

        .button:hover {
            background-color: #A9745B;
        }
        .card-label {
            background-color: #f8d7da; /* Light red */
            color: #721c24;            /* Dark red text */
            border: 2px ridge #a94442; /* Dark red ridge border */
            padding: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 0.95em;
            border-radius: 6px;
            margin-bottom: 10px;
            width: 95%;
            display: block;
        }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<h2>Orders - Kitchen View</h2>
<a href="dashboard.php?rid=<?= $restaurantId ?>">⬅ Back to Dashboard</a><br><br>

<div class="tabs">
    <a href="?rid=<?= $restaurantId ?>&tab=pending" class="tab-link <?= $tab == 'pending' ? 'active' : '' ?>">Pending Orders</a>
    <a href="?rid=<?= $restaurantId ?>&tab=active" class="tab-link <?= $tab == 'active' ? 'active' : '' ?>">Active Orders</a>
    <a href="?rid=<?= $restaurantId ?>&tab=completed" class="tab-link <?= $tab == 'completed' ? 'active' : '' ?>">Completed Orders</a>
    <a href="?rid=<?= $restaurantId ?>&tab=collected" class="tab-link <?= $tab == 'collected' ? 'active' : '' ?>">Collected Orders</a>
    <a href="?rid=<?= $restaurantId ?>&tab=archived" class="tab-link <?= $tab == 'archived' ? 'active' : '' ?>">Archived Orders</a>
</div>

<?php if (empty($orders)): ?>
    <p>No <?= strtolower($tabTitle) ?>.</p>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <?php
        $statusColors = [
            'pending' => '#f4d03f',
            'paid' => '#5dade2',
            'preparing' => '#f39c12',
            'complete' => '#58d68d',
            'collected' => '#7f8c8d'
        ];
        $bg = $statusColors[$order['status']] ?? '#DAB894';
        if ($order['status']<>"archived"){
        ?>
        <div class="order-slip">
        <a href="order_detail.php?rid=<?= $restaurantId ?>&id=<?= $order['id'] ?>" class="order-slip-link">
            <div >
                <h3>#<?= $order['id'] ?>
                    <span class="status-tag" style="background: <?= $bg ?>;">
                        <?= ucfirst($order['status']) ?>
                    </span><br>
                    <small><strong><?= htmlspecialchars($order['name']) ?></strong> <?= htmlspecialchars($order['phone']) ?></small>
<small>
    <br> Ordered: <?= date("j M Y, H:i", strtotime($order['created_at'])) ?>
    <?php
        $tz = new DateTimeZone('Africa/Johannesburg');
        $created = new DateTime($order['created_at'], $tz);
        $now = new DateTime('now', $tz);
        $diff = $now->diff($created);

        // Calculate total hours (including days)
        $totalHours = $diff->days * 24 + $diff->h;
        $minutes = $diff->i;

        echo " ({$totalHours}h {$minutes}m ago)";
    ?>
</small>                </h3>
                <?php if ($order['credit_card_payment']): ?>
                    <span class="card-label">PLEASE TAKE CARD PAYMENT</span>
                <?php endif; ?>
                <table>
                    <?php foreach ($orderMap[$order['id']] ?? [] as $item): ?>
                    <tr>
                        <td colspan="2"><strong><?= htmlspecialchars($item['name']) ?></strong> (<?= htmlspecialchars($item['variant_label']) ?>)</td>
                    </tr>
                    <tr>
                        <td>Qty: <?= $item['quantity'] ?></td>
                        <td align="right">R<?= number_format($item['unit_price'] * $item['quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <!-- Add this block to show created_at -->
            <br>
                <strong>Total: R<?= number_format($order['total'], 2) ?></strong>
                
                            </div>
        </a>
    <?php if ($order['status'] === "collected" || $order['status'] === "complete") { ?>
          
            <br><a class="button" href="orders.php?tab=<?= $tab ?>&action=archive&rid=<?= $restaurantId ?>&id=<?= $order['id'] ?>">  Archive</a>
            
    <?php } ?>
        </div>
            
        <?php }
        endforeach; ?>
<?php endif; ?>

</body>
</html>