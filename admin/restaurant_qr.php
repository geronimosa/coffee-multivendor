<?php
require_once '../includes/db.php';
require_once '../includes/phpqrcode/qrlib.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) die("Missing restaurant ID");

$stmt = $pdo->prepare("SELECT name, slug FROM restaurants WHERE id = ?");
$stmt->execute([$restaurantId]);
$restaurant = $stmt->fetch();
if (!$restaurant) die("Restaurant not found");

$menuUrl = 'https://coffee.tatu.co.za/shop/' . rawurlencode($restaurant['slug']);

// Generate large QR code to temp file
$qrTemp = tempnam(sys_get_temp_dir(), 'qr');
QRcode::png($menuUrl, $qrTemp, QR_ECLEVEL_H, 10); // size 10 = large QR
$qrBase64 = base64_encode(file_get_contents($qrTemp));
unlink($qrTemp);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Public QR - <?= htmlspecialchars($restaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/theme.css">
    <style>
        body {
            font-family: sans-serif;
            background: #f9f9f9;
            margin: 0;
            padding: 2rem 1rem;
            text-align: center;
        }
        .qr-image {
            width: 300px;
            height: 300px;
            margin: 1rem auto;
        }
        .coffee-img {
            margin-top: 30px;
            width: 100px;
        }
        .instructions {
            max-width: 400px;
            margin: 2rem auto;
            text-align: left;
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.05);
        }
        .instructions h3 {
            margin-top: 0;
            color: #333;
        }
        .instructions ol {
            padding-left: 1.2rem;
            margin: 0.5rem 0 0 0;
        }
        .instructions li {
            margin-bottom: 0.75rem;
        }
        .menu-link {
            word-break: break-all;
            font-size: 0.9rem;
            color: #007bff;
        }
    </style>
</head>
<body>

    <h2>Scan to Order at <?= htmlspecialchars($restaurant['name']) ?></h2>
    <img class="qr-image" src="data:image/png;base64,<?= $qrBase64 ?>" alt="QR Code">

    <p class="menu-link"><a href="<?= $menuUrl ?>"><?= $menuUrl ?></a></p>

    <div class="instructions">
        <h3>How it works:</h3>
        <ol>
            <li>📱 Scan the QR code to view the menu</li>
            <li>💳 Place your order and make payment</li>
            <li>📲 Wait for a WhatsApp message to notify you when your order is ready</li>
        </ol>
    </div>

    <img class="coffee-img" src="/assets/images/coffee-cup.png" alt="Coffee Cup">

</body>
</html>
