<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/phpqrcode/qrlib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, r.name, r.uid AS restaurant_id
        FROM users u
        JOIN restaurant_users ru ON u.id = ru.user_id
        JOIN restaurants r ON ru.restaurant_id = r.id
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($results) > 0) {
        $userId = $results[0]['user_id'];
        $token = bin2hex(random_bytes(16));

        $pdo->prepare("INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)")
            ->execute([$userId, $token]);

        $loginUrl = "https://coffee.tatu.co.za/admin/index.php?token=$token";

        $qrPath = __DIR__ . "/tmp/qr_$token.png";
        if (!is_dir(__DIR__ . "/tmp")) mkdir(__DIR__ . "/tmp", 0755, true);
        
        $qrPath = sys_get_temp_dir() . "/qr_$token.png";
        QRcode::png($loginUrl, $qrPath, QR_ECLEVEL_H, 6);

        $subject = "Restaurant Admin Login";
        $html = "<p>Hello,<br>Click to log in or scan the QR:</p><p><a href='$loginUrl'>$loginUrl</a></p>";
        sendMail($email, 'Admin User', $subject, $html, $qrPath);

        echo "<h2 style='margin-bottom: 1rem; font-size: 1.5rem; text-align: center; color: #333;'>Login link sent to <strong>$email</strong>.</h2>";
    } else {
        echo "<h2 style='margin-bottom: 1rem; font-size: 1.5rem; text-align: center; color: #333;'>If you are registered a Login link will be sent to <strong>$email</strong>.</h2>";
    }
}
?>

<form method="POST" style="max-width: 400px; margin: auto; padding: 2rem; background: #f9f9f9; border-radius: 1rem; box-shadow: 0 0 10px rgba(0,0,0,0.05); font-family: sans-serif;">
    <h2 style="margin-bottom: 1rem; font-size: 1.5rem; text-align: center; color: #333;">Login Link Request</h2>
    
    <label for="email" style="display: block; margin-bottom: 0.5rem; font-weight: bold; color: #555;">Email Address</label>
    <input type="email" id="email" name="email" required
           style="width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 0.5rem; font-size: 1rem; margin-bottom: 1rem;">

    <button type="submit"
            style="width: 100%; padding: 0.75rem; background-color: #4CAF50; color: white; border: none; border-radius: 0.5rem; font-size: 1rem; font-weight: bold; cursor: pointer;">
        Send Login Link
    </button>
    <p>If you are registered here, an email will be sent containing your login token.</p>
</form>