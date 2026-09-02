<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

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
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; }
        th { background-color: #f2f2f2; }
        pre { white-space: pre-wrap; word-wrap: break-word; font-size: 0.9em; }
    </style>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
</head>
<body>

<h2><?= htmlspecialchars($restaurant['name']) ?> - Menu Management</h2>
<a href="dashboard.php?rid=<?= $restaurantId ?>">⬅ Back to Dashboard</a>

<table>
    <tr>
        <th>Name</th>
        <th>Category</th>
        <th>Base Price</th>
        <th>Variants</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($items as $item): ?>
    <tr>
        <td><?= htmlspecialchars($item['name']) ?></td>
        <td><?= htmlspecialchars($item['category']) ?></td>
        <td>R<?= number_format($item['price'], 2) ?></td>
        <td>
            <pre><?= json_encode(json_decode($item['variant_options'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
        </td>
        <td>
            <a href="menu_edit.php?id=<?= $item['id'] ?>&rid=<?= $restaurantId ?>">Edit</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<br>
<a href="menu_add.php?rid=<?= $restaurantId ?>"><button>Add New Item</button></a>

</body>
</html>