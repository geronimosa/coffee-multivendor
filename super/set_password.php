<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenHash = $token !== '' ? hash('sha256', $token) : '';
$stmt = $pdo->prepare(
    'SELECT pst.id, pst.user_id, u.email, u.name
     FROM password_setup_tokens pst JOIN users u ON u.id=pst.user_id
     WHERE pst.token_hash=? AND pst.used_at IS NULL AND pst.expires_at>NOW() AND u.active=1 LIMIT 1'
);
$stmt->execute([$tokenHash]);
$setup = $stmt->fetch();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setup) {
    require_csrf();
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    if (strlen($password) < 12) {
        $error = 'Use at least 12 characters.';
    } elseif (!hash_equals($password, $confirmation)) {
        $error = 'The passwords do not match.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $setup['user_id']]);
            $pdo->prepare('UPDATE password_setup_tokens SET used_at=NOW() WHERE id=? AND used_at IS NULL')->execute([$setup['id']]);
            audit_log($pdo, 'auth.password_set', 'user', (string) $setup['user_id']);
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $setup['user_id'];
            $_SESSION['user_role'] = 'super_admin';
            $_SESSION['user_name'] = $setup['name'] ?: $setup['email'];
            redirect('/super/');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $error = 'Unable to save the password. Please request a new setup link.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set Super Admin password</title><link rel="stylesheet" href="/assets/css/super-admin.css"></head><body><main class="narrow"><section class="card">
<?php if (!$setup): ?><h1>Setup link unavailable</h1><p class="muted">This link has expired or has already been used.</p><a href="/super/login.php">Return to login</a>
<?php else: ?><h1>Create your password</h1><p class="muted"><?= e($setup['email']) ?></p><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><p><label for="password">Password</label><input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required></p><p><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></p><button type="submit">Create password and sign in</button></form><?php endif; ?>
</section></main></body></html>
