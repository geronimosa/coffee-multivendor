<?php
require_once 'includes/db.php';

$stmt = $pdo->query("SELECT id, name, uid FROM restaurants ORDER BY name");
$restaurants = $stmt->fetchAll();

// Emoji icons (or you can switch to images)
$icons = ['☕', '🍵', '🧋', '🥤', '🫖'];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Restaurant List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preload" href="images/coffee-bg.png" as="image">
    <style>
        * { box-sizing: border-box; }

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
            background: url('assets/images/coffee-bg.png') no-repeat center center fixed;
            background-size: cover;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
            z-index: -1;
        }

        .container {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.85);
            padding: 20px 15px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 1;
        }

        h1 {
            text-align: center;
            margin-bottom: 25px;
            color: #5c3a21;
            font-size: 1.5em;
        }

        h1 img {
            height: 40px;
            vertical-align: middle;
            margin-right: 10px;
        }

        .restaurant {
            background-color: #fff8f0;
            border: 1px solid #e0cfc2;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 15px;
            box-shadow: 0 3px 5px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.3s;
        }

        .restaurant:hover {
            background-color: #fff0e0;
        }

        .restaurant a {
            text-decoration: none;
            color: #4a342e;
            display: flex;
            align-items: center;
            flex-grow: 1;
        }

        .icon {
            font-size: 20px;
            margin-right: 10px;
        }

        .admin-link {
            margin-left: 10px;
            font-size: 20px;
            color: #b08b6f;
            text-decoration: none;
            cursor: pointer;
        }

        .admin-link:hover {
            color: #8c5d3c;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <img src="images/logo.png" alt="">
            ☕ Choose Your Café
        </h1>

        <?php foreach ($restaurants as $index => $restaurant): ?>
            <div class="restaurant">
                <a class="main-link" href="menu.php?rid=<?= $restaurant['id'] ?>" aria-label="Open <?= htmlspecialchars($restaurant['name']) ?> menu">
                    <span class="icon"><?= $icons[$index % count($icons)] ?></span>
                    <?= htmlspecialchars($restaurant['name']) ?>
                </a>
                <a class="admin-link" href="/admin/index.php?uid=<?= $restaurant['uid'] ?>&rid=<?= $restaurant['id'] ?>" title="Admin">&#8942;</a>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>