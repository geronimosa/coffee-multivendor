<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/phpqrcode/qrlib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, r.name, r.id AS restaurant_id
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

        $loginUrl = "https://yourdomain.com/admin/index.php?token=$token";

        $qrPath = __DIR__ . "/tmp/qr_$token.png";
        if (!is_dir(__DIR__ . "/tmp")) mkdir(__DIR__ . "/tmp", 0755, true);
        QRcode::png($loginUrl, $qrPath, QR_ECLEVEL_H, 6);

        $subject = "Restaurant Admin Login";
        $html = "<p>Hello,<br>Click to log in or scan the QR:</p><p><a href='$loginUrl'>$loginUrl</a></p>";
        sendMail($email, 'Admin User', $subject, $html, $qrPath);

        echo "<p>Login link sent to <strong>$email</strong>.</p>";
    } else {
        echo "<p>No user found with that email.</p>";
    }
}
?>

<form method="POST">
    <label>Email:</label>
    <input type="email" name="email" required>
    <button type="submit">Send Login Link</button>
</form>
