<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

// Fetch restaurant name
$stmt = $pdo->prepare("SELECT name FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) die("Restaurant not found");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?= htmlspecialchars($restaurant['name']) ?> - Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <style>
        body {
            margin: 0;
            padding: 10px;
            font-family: 'Segoe UI', sans-serif;
            background-color: #f1e7dd;
            position: relative;
            min-height: 100vh;
            z-index: 0;
        }

        body::before {
            content: "";
            background: url('../assets/images/coffee-bg.png') no-repeat center center fixed;
            background-size: cover;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }
        .dashboard {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 400px;
            box-sizing: border-box;
        }
        .dashboard h2 {
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            color: #333;
        }
        .dashboard ul {
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
        }
        .dashboard ul li {
            margin: 0.75rem 0;
        }
        .dashboard ul li a {
            text-decoration: none;
            font-weight: bold;
            color: #007bff;
            display: block;
        }
        .dashboard ul li a:hover {
            text-decoration: underline;
        }
        .dashboard p {
            text-align: center;
        }
    </style>
</head>
<body>
    <div style="display: flex; justify-content: center; align-items: flex-start; min-height: 100vh;">

    <div class="dashboard">
        <h2><?= htmlspecialchars($restaurant['name']) ?> - Dashboard</h2>

        <ul>
            <li><a href="orders.php?rid=<?= $restaurantId ?>">📦 View Orders</a></li>
            <li><a href="menu.php?rid=<?= $restaurantId ?>">🍽️ Manage Menu</a></li>
            <li><a href="report.php?rid=<?= $restaurantId ?>">📊 Daily Turnover</a></li>
            <li><a href="restaurant_qr.php?rid=<?= $restaurantId ?>" target="_blank">📱 View Public QR Code</a></li>
        </ul>

        <p><a href="index.php">⬅ Back to login</a></p>
    </div>
    </div>
</body>
</html>