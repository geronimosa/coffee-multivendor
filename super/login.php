<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'super_admin') {
    redirect('/super/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (login_user($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirect('/super/');
    }
    $error = 'The email or password is incorrect.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super Admin Login</title><link rel="stylesheet" href="/assets/css/super-admin.css">
</head>
<body><main class="narrow"><section class="card">
    <h1>Super Admin</h1><p class="muted">Manage vendors and their integrations.</p>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <p><label for="email">Email</label><input id="email" type="email" name="email" autocomplete="username" required></p>
        <p><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required></p>
        <button type="submit">Sign in</button>
    </form>
</section></main></body></html>
